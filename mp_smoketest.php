<?php
declare(strict_types=1);
/**
 * Credimax — prueba de humo contra Mercado Pago.
 *
 * Crea una preferencia real de Checkout Pro con el mismo payload que usa la
 * carga de saldo, para verificar credenciales y que la API acepte todos los
 * campos (payer, payment_methods, back_urls, external_reference).
 * No toca el ledger ni crea depósitos.
 *
 * CLI: php mp_smoketest.php
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Services\MercadoPagoService;
use App\Services\MpSubAccountService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo por CLI.\n";
    exit;
}

$mp = new MercadoPagoService();
if (!$mp->isConfigured()) {
    echo "FALLA: no hay access token cargado.\n";
    exit(1);
}

echo "Entorno: " . ($mp->isSandbox() ? 'SANDBOX' : 'PRODUCCIÓN') . "\n";

$me = $mp->me();
if (!$me['ok']) {
    echo 'FALLA: credenciales rechazadas — ' . ($me['error'] ?? '') . "\n";
    exit(1);
}
echo 'Cuenta madre: ' . ($me['data']['nickname'] ?? '?') . ' (' . ($me['data']['site_id'] ?? '?') . ")\n";

$user = App::db()->fetch("SELECT * FROM users WHERE role = 'user' ORDER BY id ASC LIMIT 1");
if (!$user) {
    echo "FALLA: no hay usuarios para armar el payload del pagador.\n";
    exit(1);
}

$service = new MpSubAccountService();
$ref = new ReflectionClass($service);

$payer = $ref->getMethod('payerPayload');
$payer->setAccessible(true);
// Se completan los datos faltantes con valores de prueba para verificar que la API
// acepta el payload enriquecido (identificación, teléfono y domicilio) aunque el
// usuario de la base todavía no los tenga cargados.
$sample = $user;
$sample['dni'] = $sample['dni'] ?: '12345678';
$sample['phone'] = $sample['phone'] ?: '1122334455';
$sample['address_street'] = $sample['address_street'] ?: 'Av. Corrientes 1234';
$sample['address_zip'] = $sample['address_zip'] ?: 'C1043';
$payerPayload = $payer->invoke($service, $sample);

$methods = $ref->getMethod('paymentMethodsPayload');
$methods->setAccessible(true);
$methodsPayload = $methods->invoke($service);

echo "Campos del pagador enviados: " . implode(', ', array_keys($payerPayload)) . "\n";

$externalRef = 'CMX-SMOKE-' . time();
$preference = [
    'items' => [[
        'id' => $externalRef,
        'title' => 'Prueba de integración Credimax',
        'description' => 'Verificación técnica de la carga de saldo',
        'category_id' => 'services',
        'quantity' => 1,
        'currency_id' => App::config('currency', 'ARS'),
        'unit_price' => 100.0,
    ]],
    'payer' => $payerPayload,
    'payment_methods' => $methodsPayload,
    'external_reference' => $externalRef,
    'statement_descriptor' => substr((string) App::config('mercadopago.statement_descriptor', 'CREDIMAX'), 0, 22),
    'back_urls' => [
        'success' => absolute_url('/wallet/mp/retorno'),
        'pending' => absolute_url('/wallet/mp/retorno'),
        'failure' => absolute_url('/wallet/mp/retorno'),
    ],
    'binary_mode' => (bool) App::config('mercadopago.binary_mode', false),
    'expires' => true,
    'expiration_date_from' => date('c'),
    'expiration_date_to' => date('c', time() + 3600),
    'metadata' => ['credimax_kind' => 'smoketest'],
];
if (str_starts_with((string) App::config('app_url', ''), 'https://')) {
    $preference['auto_return'] = 'approved';
    $preference['notification_url'] = absolute_url('/webhooks/mercadopago');
} else {
    echo "AVISO: app_url no es HTTPS — se omiten notification_url y auto_return.\n";
}

$result = $mp->createPreference($preference, 'cmx-smoke-' . $externalRef);
if (!$result['ok']) {
    echo 'FALLA al crear la preferencia (HTTP ' . $result['status'] . '): ' . ($result['error'] ?? '') . "\n";
    echo substr($result['raw'], 0, 1200) . "\n";
    exit(1);
}

$data = $result['data'];
echo "OK preferencia creada: " . ($data['id'] ?? '?') . "\n";
echo "Link de pago: " . ($mp->isSandbox()
    ? ($data['sandbox_init_point'] ?? $data['init_point'] ?? '')
    : ($data['init_point'] ?? '')) . "\n";

// Verificación de eco: MP debe devolver lo que enviamos.
$echo = [
    'external_reference' => ($data['external_reference'] ?? '') === $externalRef,
    'statement_descriptor' => ($data['statement_descriptor'] ?? '') !== '',
    'back_urls' => !empty($data['back_urls']['success']),
    'payer.email' => !empty($data['payer']['email']),
    'payer.identification' => !empty($data['payer']['identification']['number']),
    'payment_methods.installments' => (int) ($data['payment_methods']['installments'] ?? 0) > 0,
    'expiration' => (bool) ($data['expires'] ?? false),
];
foreach ($echo as $field => $present) {
    echo ($present ? '  OK    ' : '  AVISO ') . $field . "\n";
}

echo "\nPrueba de humo completada. La preferencia expira en 1 hora y no afecta el ledger.\n";
