<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static array $config = [];
    private static ?Database $db = null;

    public static function init(array $config): void
    {
        self::$config = $config;
        Session::start($config['security']['session_name'] ?? 'CREDIMAXSESSID');
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        $parts = explode('.', $key);
        $value = self::$config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = new Database(self::config('db'));
        }
        return self::$db;
    }

    public static function purgeOldLogs(?int $auditDays = null, ?int $webhookDays = null, ?int $otpDays = null, ?int $notifDays = null): int
    {
        $auditDays = $auditDays ?? (int) self::config('logs.audit_retention_days', 365);
        $webhookDays = $webhookDays ?? (int) self::config('logs.webhook_retention_days', 180);
        $otpDays = $otpDays ?? (int) self::config('logs.otp_retention_days', 60);
        $notifDays = $notifDays ?? (int) self::config('logs.notification_retention_days', 180);
        $db = self::db();
        $purged = 0;
        $queries = [
            ['audit_logs', 'created_at', $auditDays],
            ['mp_webhook_events', 'created_at', $webhookDays],
            ['otp_codes', 'created_at', $otpDays],
            ['notifications', 'created_at', $notifDays],
        ];
        foreach ($queries as [$table, $col, $days]) {
            if ($days <= 0) {
                continue;
            }
            try {
                $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
                $stmt = $db->query("DELETE FROM {$table} WHERE {$col} < ?", [$cutoff]);
                $purged += (int) $stmt->rowCount();
            } catch (\Throwable $e) {
                error_log('purgeOldLogs ' . $table . ': ' . $e->getMessage());
            }
        }
        $logDir = CREDIMAX_ROOT . '/storage/logs';
        if (is_dir($logDir)) {
            $cutoff = time() - 60 * 86400;
            foreach (['cron.log', 'mail.log', 'otp.log', 'otp-sms-queue.log', 'php-error.log'] as $fn) {
                $file = $logDir . '/' . $fn;
                if (is_file($file) && @filemtime($file) < $cutoff) {
                    @file_put_contents($file, '');
                    $purged++;
                }
            }
        }
        return $purged;
    }
}
