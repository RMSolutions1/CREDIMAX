<?php
declare(strict_types=1);
/**
 * Credimax — migración de la integración Mercado Pago.
 *
 * Crea el modelo de sub-cuentas, el espejo de pagos reales, los cobros por link/QR,
 * las órdenes de pago (cash-out) y la bitácora de webhooks.
 *
 * CLI:  php migrate_mercadopago.php
 * HTTP: /migrate_mercadopago.php?key=TU_CRON_KEY   (borrar el archivo después)
 */

require __DIR__ . '/app/bootstrap.php';

$cronKey = (string) App\Core\App::config('security.cron_key', '');
$provided = $_GET['key'] ?? ($argv[1] ?? '');
if (PHP_SAPI !== 'cli') {
    if ($cronKey === '' || str_contains($cronKey, 'CAMBIAR') || !hash_equals($cronKey, (string) $provided)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db = App\Core\App::db();
$pdo = $db->pdo();
$msgs = [];

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}

// ---------------------------------------------------------------- Tablas

$tables = [
    // Sub-cuenta: espejo por usuario contra la cuenta madre de Mercado Pago.
    'mp_subaccounts' => "CREATE TABLE `mp_subaccounts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `wallet_id` INT UNSIGNED NOT NULL,
        `external_id` VARCHAR(40) NOT NULL,
        `mp_user_id` VARCHAR(40) DEFAULT NULL,
        `mp_email` VARCHAR(190) DEFAULT NULL,
        `mp_nickname` VARCHAR(120) DEFAULT NULL,
        `public_key` VARCHAR(120) DEFAULT NULL,
        `access_token` TEXT DEFAULT NULL,
        `refresh_token` TEXT DEFAULT NULL,
        `token_expires_at` DATETIME DEFAULT NULL,
        `collected_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `paid_out_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('unlinked','linked','revoked') NOT NULL DEFAULT 'unlinked',
        `linked_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mpsub_user` (`user_id`),
        UNIQUE KEY `uq_mpsub_external` (`external_id`),
        KEY `idx_mpsub_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Espejo local de cada pago real de Mercado Pago. mp_payment_id UNIQUE = idempotencia.
    'mp_payments' => "CREATE TABLE `mp_payments` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `mp_payment_id` VARCHAR(40) DEFAULT NULL,
        `user_id` INT UNSIGNED DEFAULT NULL,
        `deposit_id` INT UNSIGNED DEFAULT NULL,
        `charge_id` INT UNSIGNED DEFAULT NULL,
        `kind` VARCHAR(20) NOT NULL DEFAULT 'topup',
        `status` VARCHAR(30) NOT NULL DEFAULT 'created',
        `status_detail` VARCHAR(60) DEFAULT NULL,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `fee_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `refunded_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `currency` CHAR(3) NOT NULL DEFAULT 'ARS',
        `payment_method_id` VARCHAR(40) DEFAULT NULL,
        `payment_type_id` VARCHAR(40) DEFAULT NULL,
        `payer_email` VARCHAR(190) DEFAULT NULL,
        `external_reference` VARCHAR(80) DEFAULT NULL,
        `preference_id` VARCHAR(80) DEFAULT NULL,
        `merchant_order_id` VARCHAR(40) DEFAULT NULL,
        `init_point` VARCHAR(500) DEFAULT NULL,
        `credited` TINYINT(1) NOT NULL DEFAULT 0,
        `credited_at` DATETIME DEFAULT NULL,
        `wallet_tx_reference` VARCHAR(64) DEFAULT NULL,
        `raw` MEDIUMTEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mp_payment_id` (`mp_payment_id`),
        KEY `idx_mp_extref` (`external_reference`),
        KEY `idx_mp_user` (`user_id`,`created_at`),
        KEY `idx_mp_status` (`status`,`credited`),
        KEY `idx_mp_deposit` (`deposit_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Links / QR de cobro a favor de una sub-cuenta.
    'mp_charges' => "CREATE TABLE `mp_charges` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `code` VARCHAR(24) NOT NULL,
        `title` VARCHAR(120) NOT NULL,
        `note` VARCHAR(255) DEFAULT NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `fee_pct` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
        `external_reference` VARCHAR(80) DEFAULT NULL,
        `preference_id` VARCHAR(80) DEFAULT NULL,
        `init_point` VARCHAR(500) DEFAULT NULL,
        `status` ENUM('open','paid','expired','cancelled') NOT NULL DEFAULT 'open',
        `paid_payment_id` VARCHAR(40) DEFAULT NULL,
        `paid_at` DATETIME DEFAULT NULL,
        `expires_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mpcharge_code` (`code`),
        UNIQUE KEY `uq_mpcharge_extref` (`external_reference`),
        KEY `idx_mpcharge_user` (`user_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Órdenes de pago (cash-out) contra la cuenta madre.
    'mp_payouts' => "CREATE TABLE `mp_payouts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `withdraw_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `destination_type` ENUM('cvu','alias','mp_account') NOT NULL DEFAULT 'cvu',
        `destination` VARCHAR(60) NOT NULL,
        `holder` VARCHAR(160) DEFAULT NULL,
        `status` ENUM('queued','sent','confirmed','failed') NOT NULL DEFAULT 'queued',
        `mp_operation_id` VARCHAR(60) DEFAULT NULL,
        `admin_id` INT UNSIGNED DEFAULT NULL,
        `notes` VARCHAR(255) DEFAULT NULL,
        `sent_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mppayout_withdraw` (`withdraw_id`),
        KEY `idx_mppayout_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Bitácora de webhooks: event_key UNIQUE evita reprocesar reintentos.
    'mp_webhook_events' => "CREATE TABLE `mp_webhook_events` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_key` VARCHAR(190) NOT NULL,
        `type` VARCHAR(40) DEFAULT NULL,
        `action` VARCHAR(60) DEFAULT NULL,
        `data_id` VARCHAR(60) DEFAULT NULL,
        `signature_valid` TINYINT(1) NOT NULL DEFAULT 0,
        `processed` TINYINT(1) NOT NULL DEFAULT 0,
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `result` VARCHAR(255) DEFAULT NULL,
        `error` VARCHAR(255) DEFAULT NULL,
        `payload` TEXT DEFAULT NULL,
        `processed_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mpevent_key` (`event_key`),
        KEY `idx_mpevent_pending` (`processed`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Estados OAuth + PKCE de la vinculación de cuentas.
    'mp_oauth_states' => "CREATE TABLE `mp_oauth_states` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `state` VARCHAR(64) NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `code_verifier` TEXT NOT NULL,
        `used` TINYINT(1) NOT NULL DEFAULT 0,
        `expires_at` DATETIME NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mpstate` (`state`),
        KEY `idx_mpstate_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    if (!tableExists($pdo, $name)) {
        $pdo->exec($sql);
        $msgs[] = "OK  tabla {$name}";
    } else {
        $msgs[] = "--  tabla {$name} ya existe";
    }
}

// -------------------------------------------------------------- Columnas

$alters = [
    ['fund_deposits', 'channel', "ALTER TABLE `fund_deposits` ADD COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER `method`"],
    ['fund_deposits', 'mp_payment_id', "ALTER TABLE `fund_deposits` ADD COLUMN `mp_payment_id` VARCHAR(40) DEFAULT NULL AFTER `external_reference`"],
    ['withdraw_requests', 'mp_operation_id', "ALTER TABLE `withdraw_requests` ADD COLUMN `mp_operation_id` VARCHAR(60) DEFAULT NULL AFTER `status`"],
    ['withdraw_requests', 'idempotency_key', "ALTER TABLE `withdraw_requests` ADD COLUMN `idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `mp_operation_id`"],
    ['wallet_transactions', 'idempotency_key', "ALTER TABLE `wallet_transactions` ADD COLUMN `idempotency_key` VARCHAR(64) DEFAULT NULL AFTER `reference`"],
];

foreach ($alters as [$table, $column, $sql]) {
    if (!tableExists($pdo, $table)) {
        $msgs[] = "!!  tabla {$table} inexistente, se omite {$column}";
        continue;
    }
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec($sql);
        $msgs[] = "OK  columna {$table}.{$column}";
    } else {
        $msgs[] = "--  columna {$table}.{$column} ya existe";
    }
}

// --------------------------------------------------------------- Índices

$indexes = [
    // Un mismo comprobante externo no puede informarse dos veces.
    ['fund_deposits', 'uq_dep_extref', "ALTER TABLE `fund_deposits` ADD UNIQUE KEY `uq_dep_extref` (`external_reference`)"],
    // Idempotencia de operaciones de billetera iniciadas por el usuario.
    ['wallet_transactions', 'uq_tx_idem', "ALTER TABLE `wallet_transactions` ADD UNIQUE KEY `uq_tx_idem` (`idempotency_key`)"],
];

foreach ($indexes as [$table, $index, $sql]) {
    if (!tableExists($pdo, $table) || indexExists($pdo, $table, $index)) {
        $msgs[] = "--  índice {$table}.{$index} ya existe u omitido";
        continue;
    }
    try {
        $pdo->exec($sql);
        $msgs[] = "OK  índice {$table}.{$index}";
    } catch (PDOException $e) {
        // Datos duplicados preexistentes: se avisa sin abortar la migración.
        $msgs[] = "!!  índice {$table}.{$index} no aplicado: " . $e->getMessage();
    }
}

// -------------------------------------------------------------- Settings

$settings = [
    'mp_enabled' => '0',
    'mp_site_id' => 'MLA',
    'mp_access_token' => '',
    'mp_public_key' => '',
    'mp_client_id' => '',
    'mp_client_secret' => '',
    'mp_webhook_secret' => '',
];

if (tableExists($pdo, 'settings')) {
    foreach ($settings as $key => $value) {
        $exists = $db->fetch('SELECT id FROM settings WHERE setting_key = ?', [$key]);
        if (!$exists) {
            $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
            $msgs[] = "OK  setting {$key}";
        }
    }
}

// ------------------------------------- Cifrado de secretos guardados en claro

if (tableExists($pdo, 'settings')) {
    $sensitive = ['mp_access_token', 'mp_client_secret', 'mp_webhook_secret'];
    $encrypted = 0;
    foreach ($sensitive as $key) {
        $row = $db->fetch('SELECT id, setting_value FROM settings WHERE setting_key = ?', [$key]);
        $value = (string) ($row['setting_value'] ?? '');
        if (!$row || $value === '' || str_starts_with($value, 'enc1:')) {
            continue;
        }
        try {
            $db->update('settings', ['setting_value' => App\Core\Crypto::encrypt($value)], 'id = ?', [(int) $row['id']]);
            $encrypted++;
        } catch (Throwable $e) {
            $msgs[] = "!!  no se pudo cifrar {$key}: " . $e->getMessage();
        }
    }
    $msgs[] = 'OK  secretos cifrados en esta corrida: ' . $encrypted;
}

// ------------------------------------------------- Backfill de sub-cuentas

if (tableExists($pdo, 'mp_subaccounts') && tableExists($pdo, 'wallets')) {
    $wallets = $db->fetchAll(
        'SELECT w.id, w.user_id FROM wallets w
         LEFT JOIN mp_subaccounts s ON s.user_id = w.user_id
         WHERE s.id IS NULL'
    );
    foreach ($wallets as $w) {
        $db->insert('mp_subaccounts', [
            'user_id' => (int) $w['user_id'],
            'wallet_id' => (int) $w['id'],
            'external_id' => 'CMX-SUB-' . str_pad((string) $w['user_id'], 8, '0', STR_PAD_LEFT),
            'status' => 'unlinked',
        ]);
    }
    $msgs[] = 'OK  sub-cuentas creadas: ' . count($wallets);
}

// Los depósitos existentes son manuales; los nuevos marcan su canal.
if (columnExists($pdo, 'fund_deposits', 'channel')) {
    $pdo->exec("UPDATE `fund_deposits` SET `channel` = 'manual' WHERE `channel` = '' OR `channel` IS NULL");
}

$out = implode("\n", $msgs) . "\n\nDONE — recordá borrar migrate_mercadopago.php en producción.\n";
echo $out;
@file_put_contents(CREDIMAX_ROOT . '/storage/logs/migrate.log', date('c') . "\n" . $out, FILE_APPEND);
