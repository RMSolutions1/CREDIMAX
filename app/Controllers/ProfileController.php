<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

final class ProfileController
{
    public function index(): void
    {
        require_auth();
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('profile/index', ['title' => 'Mi perfil', 'profile' => $user]);
    }

    public function update(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $dni = trim((string) ($_POST['dni'] ?? ''));
            App::db()->update('users', [
                'phone' => $phone !== '' ? $phone : null,
                'dni' => $dni !== '' ? $dni : null,
            ], 'id = ?', [auth_id()]);
            (new \App\Services\AuthService())->refreshSession();
            Session::flash('success', 'Perfil actualizado.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/profile'));
    }

    public function kycForm(): void
    {
        require_auth();
        $user = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        $docs = App::db()->fetchAll('SELECT * FROM kyc_documents WHERE user_id = ? ORDER BY id DESC', [auth_id()]);
        View::render('profile/kyc', ['title' => 'Verificación de identidad', 'profile' => $user, 'docs' => $docs]);
    }

    public function kycSubmit(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $uid = auth_id();
            $requiredOk = ['dni_front' => false, 'dni_back' => false, 'selfie' => false];
            foreach (['dni_front', 'dni_back', 'selfie'] as $field) {
                if (empty($_FILES[$field]['name'])) {
                    continue;
                }
                $rel = store_kyc_upload($_FILES[$field], $field, $uid);
                App::db()->insert('kyc_documents', [
                    'user_id' => $uid,
                    'doc_type' => $field,
                    'file_path' => $rel,
                    'status' => 'pending',
                ]);
                $requiredOk[$field] = true;
            }
            foreach (array_keys($requiredOk) as $need) {
                if (!$requiredOk[$need]) {
                    $ex = App::db()->fetch('SELECT id FROM kyc_documents WHERE user_id = ? AND doc_type = ?', [$uid, $need]);
                    if ($ex) {
                        $requiredOk[$need] = true;
                    }
                }
            }
            if (!$requiredOk['dni_front'] || !$requiredOk['dni_back'] || !$requiredOk['selfie']) {
                throw new \RuntimeException('Obligatorios: DNI frente, DNI dorso y selfie.');
            }

            App::db()->update('users', ['kyc_status' => 'submitted'], 'id = ?', [$uid]);
            (new \App\Services\AuthService())->refreshSession();
            Session::flash('success', 'Documentación enviada. Quedará en revisión.');
            audit_log('kyc.submit', 'user', (string) $uid);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/kyc'));
    }

    public function closeForm(): void
    {
        View::render('legal/baja', [
            'title' => 'Botón de baja',
            'authed' => (bool) auth_user(),
        ], 'layouts/marketing');
    }

    public function closeSubmit(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            if (empty($_POST['confirm_close'])) {
                throw new \RuntimeException('Debés confirmar la baja de la cuenta.');
            }
            (new \App\Services\AccountClosureService())->closeAccount(
                auth_id(),
                trim((string) ($_POST['reason'] ?? 'Baja voluntaria'))
            );
            (new \App\Services\AuthService())->logout();
            Session::flash('success', 'Tu cuenta fue dada de baja.');
            redirect(url('/'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/legales/baja'));
        }
    }
}
