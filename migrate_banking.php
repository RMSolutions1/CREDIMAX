<?php
declare(strict_types=1);

/**
 * Migración banking para instalaciones Credimax ya existentes.
 * Ejecutar una vez: /migrate_banking.php?key=CREDIMAX_MIGRATE
 * Luego borrar este archivo.
 */

require __DIR__ . '/app/bootstrap.php';

$cronKey = (string) App\Core\App::config('security.cron_key', '');
$key = $_GET['key'] ?? '';
$legacy = $key === 'CREDIMAX_MIGRATE' && (string) App\Core\App::config('app_env', 'production') !== 'production';
if (!$legacy && ($cronKey === '' || str_contains($cronKey, 'CAMBIAR') || !hash_equals($cronKey, $key))) {
    http_response_code(403);
    echo 'Forbidden. Usá ?key=security.cron_key (o CREDIMAX_MIGRATE solo en local). Borrá este archivo tras migrar.';
    exit;
}

$db = App\Core\App::db();
$pdo = $db->pdo();

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$log = [];

$walletCols = [
    'bank_id' => "VARCHAR(10) NOT NULL DEFAULT '900'",
    'account_code' => 'VARCHAR(32) NULL',
    'account_type' => "VARCHAR(40) NOT NULL DEFAULT 'CA'",
    'cvu' => 'CHAR(22) NULL',
    'cbu' => 'CHAR(22) NULL',
    'alias' => 'VARCHAR(40) NULL',
    'cuit' => 'VARCHAR(13) NULL',
];

foreach ($walletCols as $col => $def) {
    if (!columnExists($pdo, 'wallets', $col)) {
        $pdo->exec("ALTER TABLE wallets ADD COLUMN `$col` $def");
        $log[] = "wallets.$col added";
    }
}

$sqlFile = __DIR__ . '/database/schema.sql';
// Create missing banking tables by running CREATE IF NOT EXISTS excerpts from a dedicated list
$creates = [
'api_credentials' => "CREATE TABLE IF NOT EXISTS api_credentials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  api_key_prefix VARCHAR(16) NOT NULL,
  api_key_hash VARCHAR(255) NOT NULL,
  scopes TEXT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_api_prefix (api_key_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'api_jwt_blacklist' => "CREATE TABLE IF NOT EXISTS api_jwt_blacklist (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jti CHAR(32) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'beneficiaries' => "CREATE TABLE IF NOT EXISTS beneficiaries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  cvu_cbu CHAR(22) NULL,
  alias VARCHAR(40) NULL,
  cuit VARCHAR(13) NULL,
  owner_name VARCHAR(160) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ben_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'bank_transfers' => "CREATE TABLE IF NOT EXISTS bank_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id VARCHAR(40) NOT NULL UNIQUE,
  origin_id VARCHAR(15) NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  from_wallet_id INT UNSIGNED NOT NULL,
  to_user_id INT UNSIGNED NULL,
  to_wallet_id INT UNSIGNED NULL,
  to_cvu CHAR(22) NULL,
  to_alias VARCHAR(40) NULL,
  to_cuit VARCHAR(13) NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'ARS',
  concept VARCHAR(10) NOT NULL DEFAULT 'VAR',
  description VARCHAR(100) NULL,
  status ENUM('PENDING','COMPLETED','ERROR','CANCELLED') NOT NULL DEFAULT 'PENDING',
  status_description VARCHAR(120) NULL,
  wallet_tx_out VARCHAR(64) NULL,
  wallet_tx_in VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_origin_from (from_user_id, origin_id),
  INDEX idx_bt_from (from_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'debins' => "CREATE TABLE IF NOT EXISTS debins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  debin_id VARCHAR(40) NOT NULL UNIQUE,
  origin_id VARCHAR(15) NOT NULL,
  seller_user_id INT UNSIGNED NOT NULL,
  seller_wallet_id INT UNSIGNED NOT NULL,
  buyer_user_id INT UNSIGNED NULL,
  buyer_wallet_id INT UNSIGNED NULL,
  buyer_cvu CHAR(22) NULL,
  buyer_alias VARCHAR(40) NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'ARS',
  concept VARCHAR(10) NOT NULL DEFAULT 'VAR',
  description VARCHAR(100) NULL,
  provision VARCHAR(80) NULL,
  expiration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  expires_at DATETIME NOT NULL,
  status ENUM('PENDING','AWAITING_CONFIRMATION','COMPLETED','REJECTED','EXPIRED','ERROR','CANCELLED') NOT NULL DEFAULT 'AWAITING_CONFIRMATION',
  status_description VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_debin_origin (seller_user_id, origin_id),
  INDEX idx_debin_buyer (buyer_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'echeqs' => "CREATE TABLE IF NOT EXISTS echeqs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  echeq_id VARCHAR(40) NOT NULL UNIQUE,
  issuer_user_id INT UNSIGNED NOT NULL,
  issuer_wallet_id INT UNSIGNED NOT NULL,
  receiver_user_id INT UNSIGNED NULL,
  receiver_cuit VARCHAR(13) NULL,
  receiver_name VARCHAR(160) NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'ARS',
  check_type ENUM('CPD','CC') NOT NULL DEFAULT 'CPD',
  payment_date DATE NOT NULL,
  status ENUM('ACTIVE','CUSTODY','ACCREDIT','ENDORSED','REJECTED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
  current_holder_user_id INT UNSIGNED NULL,
  description VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_echeq_issuer (issuer_user_id, status),
  INDEX idx_echeq_holder (current_holder_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'echeq_actions' => "CREATE TABLE IF NOT EXISTS echeq_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  echeq_id BIGINT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  action ENUM('ISSUE','ENDORSE','DEPOSIT','REJECT','CANCEL','ACCREDIT') NOT NULL,
  note VARCHAR(255) NULL,
  meta TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'bank_events' => "CREATE TABLE IF NOT EXISTS bank_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id VARCHAR(60) NOT NULL,
  user_id INT UNSIGNED NULL,
  payload TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bank_events (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($creates as $name => $sql) {
    if (!tableExists($pdo, $name)) {
        $pdo->exec($sql);
        $log[] = "table $name created";
    } else {
        $log[] = "table $name exists";
    }
}

$settings = [
    'bank_id' => '900',
    'bank_name' => 'Credimax Bank Privado',
    'bank_view_id' => 'owner',
    'jwt_ttl_seconds' => '3600',
    'cvu_entity_code' => '900',
];
foreach ($settings as $k => $v) {
    $exists = $db->fetch('SELECT id FROM settings WHERE setting_key = ?', [$k]);
    if (!$exists) {
        $db->insert('settings', ['setting_key' => $k, 'setting_value' => $v]);
        $log[] = "setting $k";
    }
}

// Backfill CVU for existing wallets
$wallets = $db->fetchAll('SELECT * FROM wallets');
$cvu = new App\Services\CvuService();
foreach ($wallets as $w) {
    $cvu->ensureBankIdentity((int) $w['user_id'], $w);
}
$log[] = 'cvu backfill done for ' . count($wallets) . ' wallets';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'log' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
