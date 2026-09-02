<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\OtpService;
use App\Services\TotpService;
use RuntimeException;

final class OtpController
{
    public function showVerify(): void
    {
        require_auth();
        $scope = otp_pending_scope();
        if ($scope === null) {
            redirect(url('/dashboard'));
        }
        View::render('otp/verify', [
            'title' => 'Verificación requerida',
            'scope' => $scope,
            'amount' => otp_pending_amount(),
            'back' => (string) Session::get('_otp_pending_back', url('/dashboard')),
        ]);
    }

    public function submitVerify(): void
    {
        require_auth();
        Csrf::requireValid();
        $scope = otp_pending_scope();
        if ($scope === null) {
            Session::flash('error', 'No hay operación pendiente de verificación.');
            redirect(url('/dashboard'));
        }
        $code = (string) ($_POST['code'] ?? '');
        if (!otp_verify_challenge($scope, $code)) {
            Session::flash('error', 'Código inválido o expirado.');
            redirect(url('/otp/verify'));
        }
        $back = (string) Session::get('_otp_pending_back', url('/dashboard'));
        Session::forget('_otp_pending_back');
        Session::forget('_otp_pending_scope');
        Session::flash('success', 'Verificación correcta. Podés continuar la operación.');
        redirect($back);
    }

    public function resend(): void
    {
        require_auth();
        Csrf::requireValid();
        $scope = otp_pending_scope();
        if ($scope === null) {
            redirect(url('/dashboard'));
        }
        $amount = otp_pending_amount();
        otp_ensure_session_challenge($scope, $amount, null);
        Session::flash('success', 'Te enviamos un nuevo código.');
        redirect(url('/otp/verify'));
    }

    public function totpSetup(): void
    {
        require_auth();
        $uid = auth_id();
        $totp = new TotpService();
        $setup = $totp->beginSetup($uid);
        View::render('otp/totp_setup', [
            'title' => 'Activar TOTP',
            'secret' => $setup['secret'],
            'otpauth' => $setup['otpauth'],
        ]);
    }

    public function totpConfirm(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $code = (string) ($_POST['totp_code'] ?? '');
            (new TotpService())->confirmSetup(auth_id(), $code);
            (new \App\Services\AuthService())->refreshSession();
            Session::flash('success', 'TOTP activado correctamente.');
            redirect(url('/profile'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/otp/totp/setup'));
        }
    }

    public function totpDisable(): void
    {
        require_auth();
        Csrf::requireValid();
        try {
            $code = (string) ($_POST['totp_code'] ?? '');
            (new TotpService())->disable(auth_id(), $code);
            (new \App\Services\AuthService())->refreshSession();
            Session::flash('success', 'TOTP desactivado.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/profile'));
    }
}
