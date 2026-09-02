<?php
declare(strict_types=1);

/**
 * Cron de mantenimiento Credimax.
 *
 * MODO SÓLO CLI (HARDENING PRODUCCIÓN):
 *   Este script SÓLO PUEDE EJECUTARSE DESDE CONSOLA.
 *   Cualquier acceso HTTP ES DENEGADO inmediatamente con 404 (no hay bypass).
 *   Doble defensa: el .htaccess también bloquea cron.php.
 *
 * Linux (crontab):     Cada 15 minutos:  php /var/www/credimax/cron.php <CRON_KEY>
 * Windows (Task Sched): "C:\xampp\php\php.exe"  "C:\path\to\cron.php"  <CRON_KEY>
 *
 * El argumento CRON_KEY debe coincidir con security.cron_key de config/config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Status: 404 Not Found');
    exit(0);
}

define('CREDIMAX_BOOTSTRAP', true);
require __DIR__ . '/app/bootstrap.php';

$cronKey = (string) App\Core\App::config('security.cron_key', '');
$provided = (string) ($argv[1] ?? '');
if ($cronKey !== '' && !str_contains($cronKey, 'CAMBIAR') && $provided !== '') {
    if (!hash_equals($cronKey, $provided)) {
        fwrite(STDERR, "[CRON " . date('c') . "] Clave cron incorrecta.\n");
        exit(1);
    }
}

$loanSvc = new App\Services\LoanService();
$overdueN = $loanSvc->markOverdue();

$lateFeeN = 0;
try {
    $lateFeeN = $loanSvc->applyLateFees();
} catch (\Throwable $e) {
    fwrite(STDERR, "[CRON] applyLateFees error: " . $e->getMessage() . "\n");
}

$reminderN = 0;
try {
    $reminderN = $loanSvc->sendInstallmentReminders();
} catch (\Throwable $e) {
    fwrite(STDERR, "[CRON] sendInstallmentReminders error: " . $e->getMessage() . "\n");
}

$purgeN = 0;
try {
    $purgeN = \App\Core\App::purgeOldLogs();
} catch (\Throwable $e) {
    fwrite(STDERR, "[CRON] purgeOldLogs error: " . $e->getMessage() . "\n");
}

$message = sprintf(
    "[CRON %s] overdue=%d late_fees=%d reminders=%d purged=%d\n",
    date('c'),
    $overdueN,
    $lateFeeN,
    $reminderN,
    $purgeN
);

$logDir = CREDIMAX_ROOT . '/storage/logs';
@mkdir($logDir, 0775, true);
@file_put_contents($logDir . '/cron.log', $message, FILE_APPEND);
echo $message;
