<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Cifrado simétrico autenticado (AES-256-GCM) para secretos en base de datos,
 * como los access/refresh token de las cuentas Mercado Pago vinculadas.
 * La clave deriva de security.app_key, con fallback a security.jwt_secret.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc1:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('La extensión openssl es obligatoria para almacenar secretos.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('No se pudo cifrar el secreto.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        // Compatibilidad con valores guardados en claro antes de activar el cifrado.
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Secreto cifrado inválido.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar el secreto (¿cambió la clave de la app?).');
        }
        return $plain;
    }

    private static function key(): string
    {
        $secret = (string) App::config('security.app_key', '');
        if (strlen($secret) < 32) {
            $secret = (string) App::config('security.jwt_secret', '');
        }
        if (strlen($secret) < 16) {
            throw new RuntimeException('Configurá security.app_key o security.jwt_secret con al menos 32 caracteres.');
        }
        return hash('sha256', 'credimax:crypto:' . $secret, true);
    }
}
