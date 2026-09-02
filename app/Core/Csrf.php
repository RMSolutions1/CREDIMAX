<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $key = App::config('security.csrf_key', 'credimax_csrf');
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$key];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        $key = App::config('security.csrf_key', 'credimax_csrf');
        $sessionToken = $_SESSION[$key] ?? '';
        return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    public static function requireValid(): void
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::validate(is_string($token) ? $token : null)) {
            http_response_code(419);
            Session::flash('error', 'Sesión expirada o token inválido. Recargá la página.');
            redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }
    }
}
