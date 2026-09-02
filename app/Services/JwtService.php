<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * JWT HS256 propio Credimax (sin librerías externas).
 * Header Authorization: JWT <token> o Bearer <token>.
 */
final class JwtService
{
    public function issue(array $user, int $ttlSeconds = 3600): array
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));
        $payload = [
            'iss' => 'credimax',
            'sub' => (int) $user['id'],
            'cid' => $user['credimax_id'] ?? null,
            'role' => $user['role'] ?? 'user',
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => $jti,
        ];
        return [
            'token' => $this->encode($payload),
            'expires_in' => $ttlSeconds,
            'token_type' => 'JWT',
            'jti' => $jti,
        ];
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Token JWT inválido.');
        }
        [$h64, $p64, $s64] = $parts;
        $sig = $this->b64urlDecode($s64);
        $check = hash_hmac('sha256', "$h64.$p64", $this->secret(), true);
        if ($sig === false || !hash_equals($check, $sig)) {
            throw new RuntimeException('Firma JWT inválida.');
        }
        $json = $this->b64urlDecode($p64);
        $payload = json_decode($json !== false ? $json : '', true);
        if (!is_array($payload)) {
            throw new RuntimeException('Payload JWT inválido.');
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expirado.');
        }
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti !== '') {
            $blocked = App::db()->fetch(
                'SELECT id FROM api_jwt_blacklist WHERE jti = ? AND expires_at > NOW()',
                [$jti]
            );
            if ($blocked) {
                throw new RuntimeException('Token revocado.');
            }
        }
        return $payload;
    }

    public function revoke(string $jti, int $exp): void
    {
        App::db()->insert('api_jwt_blacklist', [
            'jti' => $jti,
            'expires_at' => date('Y-m-d H:i:s', $exp),
        ]);
    }

    private function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $h = $this->b64urlEncode(json_encode($header, JSON_UNESCAPED_UNICODE) ?: '{}');
        $p = $this->b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $s = $this->b64urlEncode(hash_hmac('sha256', "$h.$p", $this->secret(), true));
        return "$h.$p.$s";
    }

    private function secret(): string
    {
        $explicit = (string) App::config('security.jwt_secret', '');
        if (strlen($explicit) >= 32) {
            return hash('sha256', $explicit, true);
        }
        // Fallback solo para entornos legacy; producción exige jwt_secret en config
        $cfg = App::config();
        $seed = ($cfg['db']['pass'] ?? '') . '|' . ($cfg['app_url'] ?? 'credimax') . '|jwt-v1';
        return hash('sha256', $seed, true);
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
