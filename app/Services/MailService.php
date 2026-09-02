<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Envío de correos operativos.
 * - Si mail.enabled=false: solo log en storage/logs/mail.log (útil en local).
 * - Si mail.enabled=true: usa mail() de PHP (SMTP del hosting).
 */
final class MailService
{
    public function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $enabled = (bool) App::config('mail.enabled', false);
        $from = (string) App::config('mail.from', 'noreply@credimax.local');
        $fromName = (string) App::config('mail.from_name', App::config('app_name', 'Credimax'));
        $text = $bodyText !== '' ? $bodyText : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));

        $logLine = date('c') . " to={$to} subject=" . str_replace(["\n", "\r"], ' ', $subject) . " enabled=" . ($enabled ? '1' : '0') . "\n";
        @file_put_contents(CREDIMAX_ROOT . '/storage/logs/mail.log', $logLine, FILE_APPEND);

        if (!$enabled) {
            @file_put_contents(
                CREDIMAX_ROOT . '/storage/logs/mail.log',
                "--- BODY ---\n{$text}\n--- END ---\n",
                FILE_APPEND
            );
            return true;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeAddress($fromName, $from),
            'Reply-To: ' . $from,
            'X-Mailer: Credimax/' . (defined('CREDIMAX_VERSION') ? CREDIMAX_VERSION : '1'),
        ];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $encodedSubject, $bodyHtml, implode("\r\n", $headers));
    }

    private function encodeAddress(string $name, string $email): string
    {
        $safe = str_replace(["\n", "\r"], '', $name);
        return '=?UTF-8?B?' . base64_encode($safe) . '?= <' . $email . '>';
    }
}
