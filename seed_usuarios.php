<?php
declare(strict_types=1);
/**
 * Credimax — crea / actualiza el administrador y usuarios de prueba.
 *
 * ATENCION: HARDENING PRODUCCION.
 *   Solo puede ejecutarse:
 *     1) Desde CLI (PHP_SAPI=cli), NUNCA por HTTP/navegador.
 *     2) Si app_env NO es "production" (o si el usuario pasa --force explicitamente).
 *     3) Si install.lock NO existe o si pasa --force.
 *
 * CLI: php seed_usuarios.php [--force]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Status: 404 Not Found');
    exit(0);
}

define('CREDIMAX_BOOTSTRAP', true);
require __DIR__ . '/app/bootstrap.php';

use App\Core\App;
use App\Services\WalletService;

$env = (string) App::config('app_env', 'production');
$hasForce = in_array('--force', $argv, true);
$lockFile = __DIR__ . '/install.lock';
$locked = file_exists($lockFile);

if ($env === 'production' && !$hasForce) {
    fwrite(STDERR, "seed_usuarios.php: Entorno PRODUCCIÓN. Usá --force solo si sabés lo que hacés.\n");
    exit(2);
}
if ($locked && !$hasForce) {
    fwrite(STDERR, "seed_usuarios.php: install.lock presente. Pasar --force para volver a ejecutar.\n");
    exit(2);
}

$db = App::db();
$wallet = new WalletService();
$now = date('Y-m-d H:i:s');
$pass = 'Demo1234!';
$hash = password_hash($pass, PASSWORD_DEFAULT);

$users = [
    [
        'email' => 'admin@credimax.test',
        'credimax_id' => 'CMX-ADMIN01',
        'first_name' => 'Martina',
        'last_name' => 'Admin',
        'dni' => '20111222',
        'phone' => '1144001100',
        'role' => 'admin',
        'can_lend' => 1,
        'can_borrow' => 1,
        'kyc_status' => 'approved',
        'risk_band' => 'A',
        'risk_score' => 92,
        'monthly_income' => 2500000,
        'employment_status' => 'empleado',
        'address_city' => 'CABA',
        'address_province' => 'CABA',
        'balance' => 0,
    ],
    [
        'email' => 'inversor@credimax.test',
        'credimax_id' => 'CMX-LENDER01',
        'first_name' => 'Ana',
        'last_name' => 'Inversora',
        'dni' => '30123456',
        'phone' => '1155112233',
        'role' => 'user',
        'can_lend' => 1,
        'can_borrow' => 0,
        'kyc_status' => 'approved',
        'risk_band' => 'A',
        'risk_score' => 88,
        'monthly_income' => 1800000,
        'employment_status' => 'autonomo',
        'address_city' => 'Rosario',
        'address_province' => 'Santa Fe',
        'balance' => 1500000,
    ],
    [
        'email' => 'solicitante@credimax.test',
        'credimax_id' => 'CMX-BORROW01',
        'first_name' => 'Luis',
        'last_name' => 'Solicitante',
        'dni' => '32987654',
        'phone' => '1166223344',
        'role' => 'user',
        'can_lend' => 0,
        'can_borrow' => 1,
        'kyc_status' => 'approved',
        'risk_band' => 'B',
        'risk_score' => 71,
        'monthly_income' => 850000,
        'employment_status' => 'empleado',
        'address_city' => 'Córdoba',
        'address_province' => 'Córdoba',
        'balance' => 120000,
    ],
    [
        'email' => 'pyme@credimax.test',
        'credimax_id' => 'CMX-PYME0001',
        'first_name' => 'Taller',
        'last_name' => 'Norte SRL',
        'dni' => '30711223345',
        'phone' => '1133445566',
        'role' => 'user',
        'can_lend' => 0,
        'can_borrow' => 1,
        'kyc_status' => 'approved',
        'risk_band' => 'C',
        'risk_score' => 62,
        'monthly_income' => 4200000,
        'employment_status' => 'empresa',
        'address_city' => 'Mendoza',
        'address_province' => 'Mendoza',
        'balance' => 350000,
    ],
    [
        'email' => 'nuevo@credimax.test',
        'credimax_id' => 'CMX-NUEVO001',
        'first_name' => 'Sofía',
        'last_name' => 'Pendiente',
        'dni' => '40111222',
        'phone' => '1177889900',
        'role' => 'user',
        'can_lend' => 1,
        'can_borrow' => 1,
        'kyc_status' => 'pending',
        'risk_band' => null,
        'risk_score' => null,
        'monthly_income' => 0,
        'employment_status' => null,
        'address_city' => 'La Plata',
        'address_province' => 'Buenos Aires',
        'balance' => 0,
    ],
];

echo "Credimax — usuarios de prueba\n";
echo str_repeat('-', 56) . "\n";

foreach ($users as $u) {
    $existing = $db->fetch('SELECT id, email FROM users WHERE email = ? OR credimax_id = ?', [$u['email'], $u['credimax_id']]);
    $payload = [
        'credimax_id' => $u['credimax_id'],
        'email' => $u['email'],
        'password_hash' => $hash,
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'dni' => $u['dni'],
        'phone' => $u['phone'],
        'role' => $u['role'],
        'can_lend' => $u['can_lend'],
        'can_borrow' => $u['can_borrow'],
        'kyc_status' => $u['kyc_status'],
        'status' => 'active',
        'email_verified_at' => $now,
        'phone_verified_at' => $now,
        'terms_accepted_at' => $now,
        'privacy_accepted_at' => $now,
        'onboarding_step' => $u['kyc_status'] === 'approved' ? 'done' : 'start',
        'employment_status' => $u['employment_status'],
        'monthly_income' => $u['monthly_income'],
        'address_city' => $u['address_city'],
        'address_province' => $u['address_province'],
        'address_street' => 'Av. Corrientes 1234',
        'address_zip' => 'C1043',
        'is_pep' => 0,
    ];
    if ($u['risk_band'] !== null) {
        $payload['risk_band'] = $u['risk_band'];
        $payload['risk_score'] = $u['risk_score'];
    }

    if ($existing) {
        $id = (int) $existing['id'];
        $db->update('users', $payload, 'id = ?', [$id]);
        $action = 'actualizado';
    } else {
        $id = $db->insert('users', $payload);
        $action = 'creado';
    }

    $w = $wallet->ensureWallet($id);
    $current = round((float) ($w['available_balance'] ?? 0), 2);
    $target = (float) $u['balance'];
    if ($target > $current + 0.009) {
        $wallet->deposit($id, round($target - $current, 2), 'Saldo inicial de prueba');
    }

    $fresh = $wallet->ensureWallet($id);
    printf(
        "%-10s  %-24s  %-16s  saldo %s\n  %s  %s\n",
        $u['role'] === 'admin' ? 'ADMIN' : strtoupper($u['kyc_status'] === 'pending' ? 'KYC' : 'USER'),
        $u['email'],
        $u['credimax_id'],
        money((float) $fresh['available_balance']),
        $action,
        $u['first_name'] . ' ' . $u['last_name']
    );
}

echo str_repeat('-', 56) . "\n";
echo "Contraseña de todas las cuentas: {$pass}\n";
echo "Entrada: http://localhost/credimax/login\n";
