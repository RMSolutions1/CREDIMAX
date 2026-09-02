<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Http;
use RuntimeException;

/**
 * Cliente de la API de Mercado Pago (sin SDK, compatible con hosting compartido).
 *
 * Cubre lo que necesita la billetera Credimax:
 *  - Checkout Pro (preferencias) para cash-in y links/QR de cobro
 *  - Consulta y búsqueda de pagos para acreditar y conciliar
 *  - Devoluciones (refunds) para revertir cash-in
 *  - OAuth (Mercado Pago Connect) para vincular la cuenta MP del usuario
 *  - Validación HMAC de la firma x-signature de los webhooks
 */
final class MercadoPagoService
{
    private const API = 'https://api.mercadopago.com';
    private const AUTH = 'https://auth.mercadopago.com';

    /** Credenciales resueltas desde settings (DB) con fallback a config/config.php. */
    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        return $this->accessToken() !== '';
    }

    public function isEnabled(): bool
    {
        return (bool) $this->setting('enabled', App::config('mercadopago.enabled', false)) && $this->isConfigured();
    }

    public function accessToken(): string
    {
        return (string) $this->setting('access_token', App::config('mercadopago.access_token', ''));
    }

    public function publicKey(): string
    {
        return (string) $this->setting('public_key', App::config('mercadopago.public_key', ''));
    }

    public function clientId(): string
    {
        return (string) $this->setting('client_id', App::config('mercadopago.client_id', ''));
    }

    public function clientSecret(): string
    {
        return (string) $this->setting('client_secret', App::config('mercadopago.client_secret', ''));
    }

    public function webhookSecret(): string
    {
        return (string) $this->setting('webhook_secret', App::config('mercadopago.webhook_secret', ''));
    }

    public function siteId(): string
    {
        return (string) $this->setting('site_id', App::config('mercadopago.site_id', 'MLA'));
    }

    /** true cuando el access token es de pruebas (prefijo TEST-). */
    public function isSandbox(): bool
    {
        return str_starts_with($this->accessToken(), 'TEST-');
    }

    // ---------------------------------------------------------------- Checkout

    /**
     * Crea una preferencia de Checkout Pro.
     *
     * @param array $preference Cuerpo completo según /checkout/preferences
     * @param string $idempotencyKey Evita preferencias duplicadas ante reintentos
     */
    public function createPreference(array $preference, string $idempotencyKey): array
    {
        return $this->call('POST', '/checkout/preferences', $preference, [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);
    }

    public function getPreference(string $preferenceId): array
    {
        return $this->call('GET', '/checkout/preferences/' . rawurlencode($preferenceId));
    }

    // ----------------------------------------------------------------- Pagos

    public function getPayment(string $paymentId): array
    {
        return $this->call('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    /**
     * Busca pagos por criterios (conciliación).
     * @param array<string,string|int> $query Ej: ['external_reference' => 'CMX-DEP-12']
     */
    public function searchPayments(array $query): array
    {
        return $this->call('GET', '/v1/payments/search?' . http_build_query($query));
    }

    public function getMerchantOrder(string $orderId): array
    {
        return $this->call('GET', '/merchant_orders/' . rawurlencode($orderId));
    }

    /**
     * Devolución total (amount null) o parcial de un pago aprobado.
     */
    public function refundPayment(string $paymentId, ?float $amount, string $idempotencyKey): array
    {
        $body = $amount === null ? [] : ['amount' => round($amount, 2)];
        return $this->call('POST', '/v1/payments/' . rawurlencode($paymentId) . '/refunds', $body, [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /** Datos de la cuenta madre (collector) asociada al access token. */
    public function me(): array
    {
        return $this->call('GET', '/users/me');
    }

    // ------------------------------------------------------------------ OAuth

    /**
     * URL a la que se envía al usuario para que vincule su cuenta de Mercado Pago.
     * PKCE siempre activo: no cuesta nada y protege el código de autorización.
     */
    public function oauthAuthorizeUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        return self::AUTH . '/authorization?' . http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function oauthExchange(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->call('POST', '/oauth/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], [], false);
    }

    public function oauthRefresh(string $refreshToken): array
    {
        return $this->call('POST', '/oauth/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], [], false);
    }

    // -------------------------------------------------------------- Webhooks

    /**
     * Valida la firma HMAC-SHA256 del header x-signature.
     * Manifest: id:<data.id>;request-id:<x-request-id>;ts:<ts>;
     * Los tramos ausentes se omiten, tal como indica la documentación.
     */
    public function verifyWebhookSignature(
        string $xSignature,
        string $xRequestId,
        string $dataId,
        int $toleranceSeconds = 600
    ): bool {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            return false;
        }

        $ts = '';
        $hash = '';
        foreach (explode(',', $xSignature) as $part) {
            $pos = strpos($part, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($part, 0, $pos));
            $value = trim(substr($part, $pos + 1));
            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = $value;
            }
        }
        if ($ts === '' || $hash === '') {
            return false;
        }

        $parts = [];
        if ($dataId !== '') {
            $parts[] = 'id:' . strtolower($dataId);
        }
        if ($xRequestId !== '') {
            $parts[] = 'request-id:' . $xRequestId;
        }
        $parts[] = 'ts:' . $ts;
        $manifest = implode(';', $parts) . ';';

        if (!hash_equals(hash_hmac('sha256', $manifest, $secret), $hash)) {
            return false;
        }

        // ts llega en milisegundos; se rechazan notificaciones fuera de ventana (replay).
        if ($toleranceSeconds > 0) {
            $sent = (int) round(((float) $ts) / 1000);
            if ($sent > 0 && abs(time() - $sent) > $toleranceSeconds) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------- Internos

    /**
     * Ejecuta la llamada y normaliza la respuesta.
     * @return array{ok:bool,status:int,data:array,error:?string,raw:string}
     */
    private function call(string $method, string $path, ?array $body = null, array $headers = [], bool $auth = true): array
    {
        if ($auth) {
            $token = $this->accessToken();
            if ($token === '') {
                throw new RuntimeException('Mercado Pago no está configurado (falta access_token).');
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = Http::request($method, self::API . $path, $body, $headers);
        $data = $response['json'] ?? [];
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        $error = null;
        if (!$ok) {
            $error = $response['error']
                ?? ($data['message'] ?? $data['error'] ?? 'Error HTTP ' . $response['status']);
            if (isset($data['cause'][0]['description'])) {
                $error .= ' — ' . $data['cause'][0]['description'];
            }
        }

        return [
            'ok' => $ok,
            'status' => $response['status'],
            'data' => is_array($data) ? $data : [],
            'error' => $error === null ? null : (string) $error,
            'raw' => $response['body'],
        ];
    }

    /** Lee credenciales de la tabla settings (mp_*) y cae a config/config.php. */
    private function setting(string $key, mixed $default = null): mixed
    {
        if ($this->credentials === null) {
            $this->credentials = [];
            try {
                $rows = App::db()->fetchAll(
                    "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mp_%'"
                );
                foreach ($rows as $row) {
                    $this->credentials[substr((string) $row['setting_key'], 3)] = $row['setting_value'];
                }
            } catch (\Throwable) {
                // La tabla settings puede no existir todavía (instalación nueva).
            }
        }

        $value = $this->credentials[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if ($key === 'enabled') {
            return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }
        // Los secretos se guardan cifrados; decrypt() deja pasar los valores en claro
        // de instalaciones anteriores al cifrado.
        try {
            return Crypto::decrypt((string) $value);
        } catch (\Throwable $e) {
            error_log('Credencial MP ilegible (' . $key . '): ' . $e->getMessage());
            return $default;
        }
    }
}
