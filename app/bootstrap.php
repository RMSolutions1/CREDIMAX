<?php
declare(strict_types=1);

/**
 * Credimax bootstrap
 */

define('CREDIMAX_ROOT', dirname(__DIR__));
define('CREDIMAX_VERSION', '1.1.0');

$configFile = CREDIMAX_ROOT . '/config/config.php';
$installed = is_file($configFile);

// Si no está instalado, redirigir al instalador (excepto rutas install)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
    $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
}
$requestPath = '/' . trim((string) $requestPath, '/');
$isInstall = $requestPath === '/install' || str_starts_with($requestPath, '/install/');

if (!$installed && !$isInstall) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    header('Location: ' . ($base === '' ? '' : $base) . '/install/');
    exit;
}

$config = $installed ? require $configFile : require CREDIMAX_ROOT . '/config/config.sample.php';

date_default_timezone_set($config['timezone'] ?? 'America/Argentina/Buenos_Aires');

$sessDir = CREDIMAX_ROOT . '/storage/sessions';
if (!is_dir($sessDir)) {
    @mkdir($sessDir, 0770, true);
}
if (is_dir($sessDir) && is_writable($sessDir)) {
    ini_set('session.save_path', $sessDir);
    ini_set('session.save_handler', 'files');
}

if (($config['app_env'] ?? 'production') === 'production') {
    $requiredProductionValues = [
        'app_url' => (string) ($config['app_url'] ?? ''),
        'security.jwt_secret' => (string) ($config['security']['jwt_secret'] ?? ''),
        'security.cron_key' => (string) ($config['security']['cron_key'] ?? ''),
        'security.app_key' => (string) ($config['security']['app_key'] ?? ''),
        'db.user' => (string) ($config['db']['user'] ?? ''),
        'db.pass' => (string) ($config['db']['pass'] ?? ''),
    ];
    $invalidProductionConfig = !str_starts_with($requiredProductionValues['app_url'], 'https://')
        || empty($config['security']['install_locked'])
        || !empty($config['app_debug']);

    foreach ($requiredProductionValues as $value) {
        if ($value === '' || str_contains($value, 'CAMBIAR') || str_contains($value, 'local-dev')) {
            $invalidProductionConfig = true;
            break;
        }
    }

    if (strlen($requiredProductionValues['security.jwt_secret']) < 32
        || strlen($requiredProductionValues['security.app_key']) < 32) {
        $invalidProductionConfig = true;
    }

    if ($invalidProductionConfig) {
        http_response_code(503);
        error_log('Configuración de producción inválida o incompleta.');
        exit('Servicio no disponible.');
    }

    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    // Fail-closed: si no es localhost pero quedaron secretos de ejemplo, no arrancar.
    $appUrl = (string) ($config['app_url'] ?? '');
    $isLocalHost = str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1') || $appUrl === '';
    if (!$isLocalHost) {
        $weak = false;
        foreach ([
            (string) ($config['security']['jwt_secret'] ?? ''),
            (string) ($config['security']['cron_key'] ?? ''),
            (string) ($config['security']['app_key'] ?? ''),
        ] as $secret) {
            if ($secret === '' || str_contains($secret, 'CAMBIAR') || str_contains($secret, 'local-dev')) {
                $weak = true;
                break;
            }
        }
        if ($weak || !empty($config['app_debug'])) {
            http_response_code(503);
            error_log('Secretos de desarrollo detectados fuera de localhost.');
            exit('Servicio no disponible.');
        }
    }
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

ini_set('log_errors', '1');
$logDir = CREDIMAX_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php-error.log');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = CREDIMAX_ROOT . '/app/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once CREDIMAX_ROOT . '/app/Helpers/functions.php';

App\Core\App::init($config);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $isHtml = true;
    if (isset($_SERVER['HTTP_ACCEPT'])) {
        $accept = (string) $_SERVER['HTTP_ACCEPT'];
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            $isHtml = false;
        }
    }
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_ends_with(strtolower($reqPath), '.xml') || str_ends_with(strtolower($reqPath), '.csv') || str_ends_with(strtolower($reqPath), '.txt')) {
        $isHtml = false;
    }
    if (str_contains($reqPath, '/api/') || str_contains($reqPath, '/webhooks/')) {
        $isHtml = false;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), interest-cohort=()');
    header_remove('X-Powered-By');

    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    }

    if ($isHtml) {
        $nonce = csp_nonce();
        $csp = [
            "default-src 'self'",
            "img-src 'self' data: blob: https://images.unsplash.com https://chart.googleapis.com https://api.qrserver.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "connect-src 'self' https://api.qrserver.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "worker-src 'self'",
            "manifest-src 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-site');
    } else {
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    }
}

return $config;
