<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Services\ScoringService;

final class OnboardingController
{
    public function start(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('onboarding/start', ['title' => 'Completá tu cuenta', 'profile' => $profile]);
    }

    public function personal(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('onboarding/personal', ['title' => 'Datos personales', 'profile' => $profile]);
    }

    public function savePersonal(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $birth = trim((string) ($_POST['birth_date'] ?? ''));
            if ($birth !== '') {
                $age = (int) ((new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable('today'))->y);
                if ($age < 18 || $age > 74) {
                    throw new \RuntimeException('Debés tener entre 18 y 74 años.');
                }
            }
            App::db()->update('users', [
                'dni' => preg_replace('/\D+/', '', (string) ($_POST['dni'] ?? '')) ?: null,
                'cuit' => preg_replace('/\D+/', '', (string) ($_POST['cuit'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'birth_date' => $birth !== '' ? $birth : null,
                'gender' => in_array($_POST['gender'] ?? '', ['F', 'M', 'X'], true) ? $_POST['gender'] : null,
                'address_street' => trim((string) ($_POST['address_street'] ?? '')) ?: null,
                'address_city' => trim((string) ($_POST['address_city'] ?? '')) ?: null,
                'address_province' => trim((string) ($_POST['address_province'] ?? '')) ?: null,
                'address_zip' => trim((string) ($_POST['address_zip'] ?? '')) ?: null,
                'onboarding_step' => 'contact',
            ], 'id = ?', [auth_id()]);
            (new AuthService())->refreshSession();
            Session::flash('success', 'Datos personales guardados.');
            redirect(url('/onboarding/contacto'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/onboarding/personal'));
        }
    }

    public function contact(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('onboarding/contact', ['title' => 'Verificación de contacto', 'profile' => $profile]);
    }

    public function sendOtp(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $channel = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
            $code = null;
            (new OtpService())->send(auth_id(), $channel);
            Session::flash('success', 'Código enviado por ' . ($channel === 'sms' ? 'SMS' : 'email') . '. Revisá tu bandeja (válido 10 min).');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/onboarding/contacto'));
    }

    public function verifyOtp(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $channel = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
            (new OtpService())->verify(auth_id(), $channel, (string) ($_POST['code'] ?? ''));
            $field = $channel === 'sms' ? 'phone_verified_at' : 'email_verified_at';
            App::db()->update('users', [
                $field => date('Y-m-d H:i:s'),
                'onboarding_step' => 'employment',
            ], 'id = ?', [auth_id()]);
            Session::flash('success', 'Contacto verificado.');
            redirect(url('/onboarding/laboral'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/onboarding/contacto'));
        }
    }

    public function employment(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('onboarding/employment', ['title' => 'Situación laboral', 'profile' => $profile]);
    }

    public function saveEmployment(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $raw = trim((string) ($_POST['monthly_income'] ?? '0'));
            $income = str_contains($raw, ',')
                ? (float) str_replace(['.', ','], ['', '.'], $raw)
                : (float) $raw;
            App::db()->update('users', [
                'employment_status' => trim((string) ($_POST['employment_status'] ?? '')) ?: null,
                'employer_name' => trim((string) ($_POST['employer_name'] ?? '')) ?: null,
                'job_seniority_months' => (int) ($_POST['job_seniority_months'] ?? 0),
                'monthly_income' => max(0, $income),
                'income_type' => trim((string) ($_POST['income_type'] ?? '')) ?: null,
                'onboarding_step' => 'pep',
            ], 'id = ?', [auth_id()]);
            Session::flash('success', 'Datos laborales guardados.');
            redirect(url('/onboarding/pep'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/onboarding/laboral'));
        }
    }

    public function pepForm(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        View::render('onboarding/pep', ['title' => 'Declaración PEP', 'profile' => $profile]);
    }

    public function savePep(): void
    {
        require_auth();
        Csrf::requireValid();
        if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])) {
            Session::flash('error', 'Debés aceptar Términos y Privacidad.');
            redirect(url('/onboarding/pep'));
        }
        $isPep = isset($_POST['is_pep']) ? 1 : 0;
        App::db()->update('users', [
            'is_pep' => $isPep,
            'pep_detail' => $isPep ? trim((string) ($_POST['pep_detail'] ?? '')) : null,
            'terms_accepted_at' => date('Y-m-d H:i:s'),
            'privacy_accepted_at' => date('Y-m-d H:i:s'),
            'onboarding_step' => 'kyc',
        ], 'id = ?', [auth_id()]);
        Session::flash('success', 'Declaración registrada.');
        redirect(url('/onboarding/kyc'));
    }

    public function kycWizard(): void
    {
        require_auth();
        $profile = App::db()->fetch('SELECT * FROM users WHERE id = ?', [auth_id()]);
        $docs = App::db()->fetchAll('SELECT * FROM kyc_documents WHERE user_id = ? ORDER BY id DESC', [auth_id()]);
        $score = (new ScoringService())->preview(auth_id());
        View::render('onboarding/kyc', [
            'title' => 'Verificación de identidad',
            'profile' => $profile,
            'docs' => $docs,
            'score' => $score,
        ]);
    }

    public function kycSubmit(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $uid = auth_id();
            $uploaded = 0;
            $requiredOk = ['dni_front' => false, 'dni_back' => false, 'selfie' => false];
            foreach (['dni_front', 'dni_back', 'selfie', 'proof_address', 'proof_income'] as $field) {
                if (empty($_FILES[$field]['name'])) {
                    continue;
                }
                $rel = store_kyc_upload($_FILES[$field], $field, $uid);
                $docType = $field === 'proof_income' ? 'other' : $field;
                if (!in_array($docType, ['dni_front', 'dni_back', 'selfie', 'proof_address', 'other'], true)) {
                    $docType = 'other';
                }
                App::db()->insert('kyc_documents', [
                    'user_id' => $uid,
                    'doc_type' => $docType,
                    'file_path' => $rel,
                    'status' => 'pending',
                ]);
                $uploaded++;
                if (isset($requiredOk[$field])) {
                    $requiredOk[$field] = true;
                }
            }
            // Check existing docs for required
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
            $score = (new ScoringService())->evaluate($uid);
            App::db()->update('users', [
                'kyc_status' => 'submitted',
                'onboarding_step' => 'done',
                'risk_score' => $score['score'],
                'risk_band' => $score['band'],
            ], 'id = ?', [$uid]);
            (new AuthService())->refreshSession();
            notify($uid, 'KYC enviado', 'Documentación en revisión. Score preliminar: ' . $score['band'], url('/onboarding/kyc'));
            Session::flash('success', 'Enviado. Score preliminar: ' . $score['band'] . ' (' . $score['score'] . '/100).');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/onboarding/kyc'));
    }
}
