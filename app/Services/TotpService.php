<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Session;
use RuntimeException;

/** TOTP RFC 6238 sin dependencias externas. */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    public function beginSetup(int $userId): array
    {
        $secret = $this->generateSecret();
        Session::set('_totp_pending_secret', $secret);
        $user = App::db()->fetch('SELECT email FROM users WHERE id = ?', [$userId]);
        $label = rawurlencode((string) ($user['email'] ?? ('user' . $userId)));
        $issuer = rawurlencode(totp_issuer_label());
        $otpauth = 'otpauth://totp/' . $issuer . ':' . $label . '?secret=' . $secret . '&issuer=' . $issuer . '&period=' . self::PERIOD;
        return ['secret' => $secret, 'otpauth' => $otpauth];
    }

    public function confirmSetup(int $userId, string $code): void
    {
        $secret = (string) Session::get('_totp_pending_secret', '');
        if ($secret === '') {
            throw new RuntimeException('La configuración TOTP expiró. Volvé a iniciar el proceso.');
        }
        if (!$this->verifyCode($secret, trim($code))) {
            throw new RuntimeException('Código TOTP inválido.');
        }
        $this->ensureColumns();
        $enc = Crypto::encrypt($secret);
        App::db()->update('users', [
            'two_factor_mode' => 'totp',
            'totp_secret_enc' => $enc,
        ], 'id = ?', [$userId]);
        Session::forget('_totp_pending_secret');
    }

    public function disable(int $userId, string $code): void
    {
        if (!$this->verifyForUser($userId, trim($code))) {
            throw new RuntimeException('Código TOTP inválido.');
        }
        $this->ensureColumns();
        App::db()->update('users', [
            'two_factor_mode' => 'email_otp',
            'totp_secret_enc' => null,
        ], 'id = ?', [$userId]);
    }

    public function verifyForUser(int $userId, string $code): bool
    {
        $this->ensureColumns();
        $user = App::db()->fetch('SELECT two_factor_mode, totp_secret_enc FROM users WHERE id = ?', [$userId]);
        if (!$user || ($user['two_factor_mode'] ?? '') !== 'totp') {
            return false;
        }
        $enc = (string) ($user['totp_secret_enc'] ?? '');
        if ($enc === '') {
            return false;
        }
        try {
            $secret = Crypto::decrypt($enc);
        } catch (\Throwable $e) {
            return false;
        }
        return $this->verifyCode($secret, $code);
    }

    private function verifyCode(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $time = time();
        for ($offset = -1; $offset <= 1; $offset++) {
            $counter = intdiv($time, self::PERIOD) + $offset;
            if (hash_equals($this->hotp($secret, $counter), $code)) {
                return true;
            }
        }
        return false;
    }

    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );
        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($secret) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }

    private function ensureColumns(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $pdo = App::db()->pdo();
        $cols = [
            'two_factor_mode' => "ALTER TABLE users ADD COLUMN two_factor_mode VARCHAR(20) NOT NULL DEFAULT 'email_otp'",
            'totp_secret_enc' => 'ALTER TABLE users ADD COLUMN totp_secret_enc TEXT NULL',
        ];
        foreach ($cols as $col => $sql) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute(['users', $col]);
            if ((int) $st->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
        $ready = true;
    }
}
