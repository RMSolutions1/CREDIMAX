<?php
declare(strict_types=1);
/**
 * Credimax — diagnóstico integral.
 *
 * Revisa entorno, esquema, integridad del ledger y salud de la integración
 * con Mercado Pago. Solo lee: nunca modifica saldos.
 *
 * CLI:  php diagnostico.php
 * HTTP: /diagnostico.php?key=TU_CRON_KEY   (borrar el archivo en producción)
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Services\MercadoPagoService;

$cronKey = (string) App::config('security.cron_key', '');
$provided = $_GET['key'] ?? ($argv[1] ?? '');
if (PHP_SAPI !== 'cli') {
    if ($cronKey === '' || str_contains($cronKey, 'CAMBIAR') || !hash_equals($cronKey, (string) $provided)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$fails = 0;
$warns = 0;

function ok(string $msg): void
{
    echo "  OK    " . $msg . "\n";
}

function warn(string $msg): void
{
    global $warns;
    $warns++;
    echo "  AVISO " . $msg . "\n";
}

function bad(string $msg): void
{
    global $fails;
    $fails++;
    echo "  FALLA " . $msg . "\n";
}

function section(string $title): void
{
    echo "\n== " . $title . "\n";
}

// --------------------------------------------------------------- Entorno
section('Entorno');
version_compare(PHP_VERSION, '8.1.0', '>=')
    ? ok('PHP ' . PHP_VERSION)
    : bad('PHP ' . PHP_VERSION . ' — se requiere 8.1 o superior');

foreach (['pdo_mysql' => true, 'openssl' => true, 'curl' => false, 'mbstring' => true, 'gd' => false] as $ext => $required) {
    if (extension_loaded($ext)) {
        ok('extensión ' . $ext);
    } elseif ($required) {
        bad('falta la extensión ' . $ext);
    } else {
        warn('extensión ' . $ext . ' ausente (opcional)');
    }
}

$appUrl = (string) App::config('app_url', '');
str_starts_with($appUrl, 'https://')
    ? ok('app_url con HTTPS: ' . $appUrl)
    : warn('app_url sin HTTPS (' . $appUrl . '): Mercado Pago no acepta webhooks ni auto_return por HTTP');

$appKey = (string) App::config('security.app_key', '');
strlen($appKey) >= 32 && !str_contains($appKey, 'CAMBIAR')
    ? ok('security.app_key definida')
    : bad('security.app_key ausente o de ejemplo: los secretos no se pueden cifrar');

// ---------------------------------------------------------------- Esquema
section('Esquema de base de datos');
$db = App::db();
$pdo = $db->pdo();

$required = [
    'users', 'wallets', 'wallet_transactions', 'fund_deposits', 'withdraw_requests',
    'platform_treasury', 'admin_ledger', 'qr_payments', 'loans', 'loan_installments',
    'loan_fundings', 'settings', 'notifications',
    'mp_subaccounts', 'mp_payments', 'mp_charges', 'mp_payouts', 'mp_webhook_events', 'mp_oauth_states',
];
$existing = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $existing[strtolower((string) $t)] = true;
}
$missingTables = array_values(array_filter($required, static fn($t) => !isset($existing[$t])));
$missingTables === []
    ? ok(count($required) . ' tablas núcleo presentes')
    : bad('faltan tablas: ' . implode(', ', $missingTables) . ' — ejecutá migrate_mercadopago.php');

$idemIndex = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions' AND INDEX_NAME = 'uq_tx_idem'"
)->fetchColumn();
(int) $idemIndex > 0
    ? ok('índice único de idempotencia en wallet_transactions')
    : bad('falta uq_tx_idem: un doble clic puede duplicar una transferencia');

// ------------------------------------------------------ Integridad ledger
section('Integridad del ledger');

$broken = (int) $db->fetch(
    'SELECT COUNT(*) c FROM wallets WHERE ROUND(balance, 2) <> ROUND(available_balance + reserved_balance, 2)'
)['c'];
$broken === 0
    ? ok('todas las billeteras cumplen balance = disponible + reservado')
    : bad($broken . ' billetera(s) descuadradas entre balance y disponible+reservado');

$negatives = (int) $db->fetch(
    'SELECT COUNT(*) c FROM wallets WHERE balance < 0 OR available_balance < 0 OR reserved_balance < 0'
)['c'];
$negatives === 0
    ? ok('sin saldos negativos')
    : bad($negatives . ' billetera(s) con saldo negativo');

$customerLedger = (float) $db->fetch(
    "SELECT COALESCE(SUM(w.balance),0) s FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.role = 'user'"
)['s'];
$treasury = $db->fetch('SELECT * FROM platform_treasury WHERE id = 1') ?: ['third_party_aum' => 0, 'own_balance' => 0];
$aum = (float) $treasury['third_party_aum'];
$gap = round($customerLedger - $aum, 2);
echo '  ---   saldo de clientes: ' . number_format($customerLedger, 2) . ' | AUM tesorería: ' . number_format($aum, 2) . "\n";
abs($gap) < 1.0
    ? ok('AUM de terceros coincide con la suma de billeteras')
    : warn('desvío de ' . number_format($gap, 2) . ' entre billeteras y AUM (revisar ajustes manuales)');

$dupIdem = (int) $db->fetch(
    'SELECT COUNT(*) c FROM (
        SELECT idempotency_key FROM wallet_transactions
        WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1
     ) x'
)['c'];
$dupIdem === 0
    ? ok('sin claves de idempotencia repetidas')
    : bad($dupIdem . ' clave(s) de idempotencia duplicadas: hubo operaciones dobles');

$badInstallments = (int) $db->fetch(
    'SELECT COUNT(*) c FROM loan_installments
     WHERE ROUND(total_amount, 2) <> ROUND(principal_portion + interest_portion + fee_portion, 2)'
)['c'];
$badInstallments === 0
    ? ok('cuotas cuadradas (capital + interés + comisión = total)')
    : bad($badInstallments . ' cuota(s) con total distinto a la suma de sus componentes');

$overFunded = (int) $db->fetch(
    'SELECT COUNT(*) c FROM loans WHERE ROUND(funded_amount, 2) > ROUND(principal, 2) + 0.01'
)['c'];
$overFunded === 0
    ? ok('ningún crédito sobrefondeado')
    : bad($overFunded . ' crédito(s) con fondeo mayor al capital');

$orphanFunding = (int) $db->fetch(
    "SELECT COUNT(*) c FROM loans l
     WHERE l.status IN ('active','completed')
       AND ROUND(l.principal,2) <> ROUND((SELECT COALESCE(SUM(f.amount),0) FROM loan_fundings f
            WHERE f.loan_id = l.id AND f.status IN ('active','completed')), 2)"
)['c'];
$orphanFunding === 0
    ? ok('créditos desembolsados con fondeo completo')
    : warn($orphanFunding . ' crédito(s) activos cuyo fondeo no suma el capital');

// ----------------------------------------------------------- Mercado Pago
section('Mercado Pago');
$mp = new MercadoPagoService();

if (!$mp->isConfigured()) {
    warn('sin access_token cargado — configuralo en /admin/mercadopago');
} else {
    ok('access token cargado (' . ($mp->isSandbox() ? 'SANDBOX de pruebas' : 'PRODUCCIÓN') . ')');
    $mp->isEnabled() ? ok('integración habilitada') : warn('integración deshabilitada por configuración');

    if ($mp->webhookSecret() !== '') {
        ok('secreto de webhook configurado (firma HMAC validada)');
    } elseif (App::config('app_env') === 'production') {
        bad('sin webhook_secret: en producción las notificaciones no se pueden autenticar');
    } else {
        warn('sin webhook_secret: en local se aceptan notificaciones sin HMAC; en producción es obligatorio');
    }

    if ($mp->clientId() !== '' && $mp->clientSecret() !== '') {
        ok('OAuth configurado (vinculación de cuentas de usuario)');
    } elseif ($mp->clientId() !== '') {
        warn('client_id cargado; falta client_secret (se emite al activar credenciales de producción)');
    } else {
        warn('sin client_id/client_secret: los usuarios no pueden vincular su cuenta');
    }

    $me = $mp->me();
    if ($me['ok']) {
        ok('cuenta madre: ' . ($me['data']['nickname'] ?? '?') . ' — id ' . ($me['data']['id'] ?? '?')
            . ' — site ' . ($me['data']['site_id'] ?? '?'));
        if (($me['data']['site_id'] ?? '') !== $mp->siteId()) {
            warn('site_id configurado (' . $mp->siteId() . ') distinto al de la cuenta (' . ($me['data']['site_id'] ?? '?') . ')');
        }
    } else {
        bad('Mercado Pago rechazó las credenciales: ' . ($me['error'] ?? 'error desconocido'));
    }
}

$pendingCredit = (int) $db->fetch(
    "SELECT COUNT(*) c FROM mp_payments WHERE status = 'approved' AND credited = 0"
)['c'];
$pendingCredit === 0
    ? ok('no hay pagos aprobados sin acreditar')
    : bad($pendingCredit . ' pago(s) aprobados sin acreditar — corré la conciliación en /admin/mercadopago');

$failedEvents = (int) $db->fetch(
    'SELECT COUNT(*) c FROM mp_webhook_events WHERE processed = 0 AND error IS NOT NULL'
)['c'];
$failedEvents === 0
    ? ok('sin webhooks con error pendiente')
    : warn($failedEvents . ' webhook(s) con error sin reprocesar');

$queuedPayouts = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM mp_payouts WHERE status = 'queued'");
(int) $queuedPayouts['c'] === 0
    ? ok('sin órdenes de pago pendientes')
    : warn((int) $queuedPayouts['c'] . ' orden(es) de pago por ' . number_format((float) $queuedPayouts['s'], 2) . ' esperando ejecución');

$plaintextSecrets = (int) $db->fetch(
    "SELECT COUNT(*) c FROM settings
     WHERE setting_key IN ('mp_access_token','mp_client_secret','mp_webhook_secret')
       AND setting_value <> '' AND setting_value NOT LIKE 'enc1:%'"
)['c'];
$plaintextSecrets === 0
    ? ok('secretos de Mercado Pago cifrados en base')
    : warn($plaintextSecrets . ' secreto(s) guardados en claro: reguardalos en /admin/mercadopago para cifrarlos');

// ------------------------------------------------------- Rutas y vistas
section('Rutas y vistas');

$router = new \App\Core\Router();
require __DIR__ . '/app/routes.php';

$prop = (new ReflectionClass($router))->getProperty('routes');
$prop->setAccessible(true);
/** @var array<string,array<string,mixed>> $routes */
$routes = $prop->getValue($router);

$routeCount = 0;
$brokenRoutes = [];
foreach ($routes as $method => $paths) {
    foreach ($paths as $path => $handler) {
        $routeCount++;
        if (!is_array($handler)) {
            continue;
        }
        [$class, $action] = $handler;
        if (!class_exists($class)) {
            $brokenRoutes[] = $method . ' ' . $path . ' → clase inexistente ' . $class;
        } elseif (!method_exists($class, $action)) {
            $brokenRoutes[] = $method . ' ' . $path . ' → falta ' . $class . '::' . $action . '()';
        }
    }
}
$brokenRoutes === []
    ? ok($routeCount . ' rutas apuntan a controladores existentes')
    : bad(count($brokenRoutes) . ' ruta(s) rotas: ' . implode(' | ', array_slice($brokenRoutes, 0, 8)));

$viewsMissing = [];
$viewsSeen = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/app'));
foreach ($scan as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/View::render\(\s*'([^']+)'/", $code, $m) === 0) {
        continue;
    }
    foreach ($m[1] as $view) {
        if (isset($viewsSeen[$view])) {
            continue;
        }
        $viewsSeen[$view] = true;
        if (!is_file(__DIR__ . '/views/' . $view . '.php')) {
            $viewsMissing[] = $view;
        }
    }
}
$viewsMissing === []
    ? ok(count($viewsSeen) . ' vistas referenciadas existen en disco')
    : bad('faltan vistas: ' . implode(', ', $viewsMissing));

$viewErrors = [];
$viewScan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/views'));
$viewTotal = 0;
foreach ($viewScan as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $viewTotal++;
    $tokens = @token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
    if ($tokens === false) {
        $viewErrors[] = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file->getPathname());
    }
}
$viewErrors === []
    ? ok($viewTotal . ' vistas compilan sin errores de sintaxis')
    : bad('vistas con error de sintaxis: ' . implode(', ', $viewErrors));

// ------------------------------------------------------------- Resultado
section('Resultado');
echo '  ' . $fails . " fallas, " . $warns . " avisos\n";
echo $fails === 0
    ? "\nSISTEMA OPERATIVO. Revisá los avisos antes de operar con dinero real.\n"
    : "\nHAY FALLAS QUE BLOQUEAN LA OPERACIÓN. Resolvelas antes de habilitar cobros.\n";

exit($fails === 0 ? 0 : 1);
