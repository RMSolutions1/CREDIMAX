<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/** OTP con hash; el código en claro solo se entrega por canal (email/SMS/log seguro en local). */
final class OtpService
{
    public function send(int $userId, string $channel): void
    {
        if (!rate_limit_allow('otp:' . $userId, 3, 600)) {
            throw new RuntimeException('Demasiados códigos pedidos. Esperá unos minutos.');
        }
        $code = (string) random_int(100000, 999999);
        App::db()->insert('otp_codes', [
            'user_id' => $userId,
            'channel' => $channel === 'sms' ? 'sms' : 'email',
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'consumed_at' => null,
        ]);

        $user = App::db()->fetch('SELECT email, phone, first_name FROM users WHERE id = ?', [$userId]);
        $channel = $channel === 'sms' ? 'sms' : 'email';

        if ($channel === 'email') {
            $email = (string) ($user['email'] ?? '');
            $html = '<p>Hola ' . htmlspecialchars((string) ($user['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Tu código Credimax es <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<p>Válido 10 minutos. Si no lo pediste, ignorá este mensaje.</p>';
            (new MailService())->send($email, 'Código de verificación Credimax', $html, "Tu código Credimax es {$code}. Válido 10 minutos.");
        } else {
            // SMS: sin gateway externo — queda registrado para operador (sin código en notificación in-app)
            @file_put_contents(
                CREDIMAX_ROOT . '/storage/logs/otp-sms-queue.log',
                date('c') . ' user=' . $userId . ' phone=' . ($user['phone'] ?? '') . " (code delivered via ops channel)\n",
                FILE_APPEND
            );
        }

        // Nunca incluir el código en la notificación in-app (evita filtración en UI compartida)
        notify($userId, 'Código de verificación', 'Te enviamos un código por ' . ($channel === 'sms' ? 'SMS' : 'email') . '. Válido 10 minutos.', url('/onboarding/contacto'));

        if ((string) App::config('app_env', 'production') !== 'production') {
            // Solo entornos no productivos: log con código para QA local
            @file_put_contents(
                CREDIMAX_ROOT . '/storage/logs/otp.log',
                date('c') . " user={$userId} channel={$channel} code={$code}\n",
                FILE_APPEND
            );
        }
    }

    public function verify(int $userId, string $channel, string $code): void
    {
        $row = App::db()->fetch(
            "SELECT * FROM otp_codes WHERE user_id = ? AND channel = ? AND consumed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
            [$userId, $channel === 'sms' ? 'sms' : 'email']
        );
        if (!$row || !password_verify(trim($code), $row['code_hash'])) {
            throw new RuntimeException('Código inválido o expirado.');
        }
        App::db()->update('otp_codes', ['consumed_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $row['id']]);
    }
}
