<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

final class AuthController
{
    public function showLogin(): void
    {
        if (auth_user()) {
            redirect(url('/dashboard'));
        }
        View::render('auth/login', ['title' => 'Ingresar'], 'layouts/auth');
    }

    public function login(): void
    {
        Csrf::requireValid();
        try {
            (new AuthService())->login($_POST['email'] ?? '', $_POST['password'] ?? '');
            Session::flash('success', 'Sesión iniciada correctamente.');
            redirect(url('/dashboard'));
        } catch (\Throwable $e) {
            Session::set('_old', ['email' => $_POST['email'] ?? '']);
            Session::flash('error', $e->getMessage());
            redirect(url('/login'));
        }
    }

    public function showRegister(): void
    {
        if (auth_user()) {
            redirect(url('/dashboard'));
        }
        View::render('auth/register', ['title' => 'Crear cuenta'], 'layouts/auth');
    }

    public function register(): void
    {
        Csrf::requireValid();
        Session::set('_old', $_POST);
        try {
            if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])) {
                throw new \RuntimeException('Debés aceptar Términos y Privacidad para crear la cuenta.');
            }
            if (empty($_POST['resident_ar'])) {
                throw new \RuntimeException('Credimax opera solo en la República Argentina. Debés declarar residencia en el país.');
            }
            if (empty($_POST['accept_adhesion'])) {
                throw new \RuntimeException('Debés aceptar el Contrato de adhesión y el esquema de fideicomiso/segregación.');
            }
            if (empty($_POST['accept_risk'])) {
                throw new \RuntimeException('Debés aceptar el aviso de riesgo y el marco regulatorio.');
            }
            (new AuthService())->register($_POST);
            (new AuthService())->login($_POST['email'] ?? '', $_POST['password'] ?? '');
            $accountType = ($_POST['account_type'] ?? '') === 'pyme' ? 'pyme' : 'persona';
            \App\Core\App::db()->update('users', [
                'terms_accepted_at' => date('Y-m-d H:i:s'),
                'privacy_accepted_at' => date('Y-m-d H:i:s'),
                'fideicomiso_accepted_at' => date('Y-m-d H:i:s'),
                'account_type' => $accountType,
                'onboarding_step' => 'personal',
            ], 'id = ?', [auth_id()]);
            Session::forget('_old');
            Session::flash('success', 'Cuenta creada. Completá tu verificación para operar créditos.');
            redirect(url('/onboarding'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/register'));
        }
    }

    public function showForgot(): void
    {
        if (auth_user()) {
            redirect(url('/dashboard'));
        }
        View::render('auth/forgot', ['title' => 'Recuperar contraseña'], 'layouts/auth');
    }

    public function forgot(): void
    {
        Csrf::requireValid();
        if (!rate_limit_allow('password_reset', 5, 600)) {
            Session::flash('error', 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.');
            redirect(url('/forgot-password'));
        }
        try {
            (new AuthService())->requestPasswordReset((string) ($_POST['email'] ?? ''));
            Session::flash('success', 'Si el email existe, enviamos instrucciones para restablecer la contraseña.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect(url('/forgot-password'));
    }

    public function showReset(): void
    {
        if (auth_user()) {
            redirect(url('/dashboard'));
        }
        View::render('auth/reset', [
            'title' => 'Nueva contraseña',
            'token' => (string) ($_GET['token'] ?? ''),
        ], 'layouts/auth');
    }

    public function reset(): void
    {
        Csrf::requireValid();
        try {
            (new AuthService())->resetPassword(
                (string) ($_POST['token'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );
            Session::flash('success', 'Contraseña actualizada. Ya podés ingresar.');
            redirect(url('/login'));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            redirect(url('/reset-password?token=' . urlencode((string) ($_POST['token'] ?? ''))));
        }
    }

    public function logout(): void
    {
        Csrf::requireValid();
        (new AuthService())->logout();
        redirect(url('/'));
    }
}
