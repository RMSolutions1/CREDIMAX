<?php
declare(strict_types=1);

/**
 * Migración operativa a producción (idempotente).
 * CLI: php migrate_production.php
 * HTTP: /migrate_production.php?key=TU_CRON_KEY  (luego BORRAR este archivo)
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
}

$db = App\Core\App::db();
$pdo = $db->pdo();
$msgs = [];

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

$alters = [
    ['users', 'cuit', "ALTER TABLE users ADD COLUMN cuit VARCHAR(13) NULL AFTER dni"],
    ['users', 'gender', "ALTER TABLE users ADD COLUMN gender CHAR(1) NULL AFTER birth_date"],
    ['users', 'address_street', "ALTER TABLE users ADD COLUMN address_street VARCHAR(160) NULL AFTER phone"],
    ['users', 'address_city', "ALTER TABLE users ADD COLUMN address_city VARCHAR(80) NULL AFTER address_street"],
    ['users', 'address_province', "ALTER TABLE users ADD COLUMN address_province VARCHAR(80) NULL AFTER address_city"],
    ['users', 'address_zip', "ALTER TABLE users ADD COLUMN address_zip VARCHAR(20) NULL AFTER address_province"],
    ['users', 'employment_status', "ALTER TABLE users ADD COLUMN employment_status VARCHAR(40) NULL"],
    ['users', 'employer_name', "ALTER TABLE users ADD COLUMN employer_name VARCHAR(120) NULL"],
    ['users', 'job_seniority_months', "ALTER TABLE users ADD COLUMN job_seniority_months INT UNSIGNED NULL DEFAULT 0"],
    ['users', 'monthly_income', "ALTER TABLE users ADD COLUMN monthly_income DECIMAL(14,2) NULL DEFAULT 0"],
    ['users', 'income_type', "ALTER TABLE users ADD COLUMN income_type VARCHAR(40) NULL"],
    ['users', 'is_pep', "ALTER TABLE users ADD COLUMN is_pep TINYINT(1) NOT NULL DEFAULT 0"],
    ['users', 'pep_detail', "ALTER TABLE users ADD COLUMN pep_detail VARCHAR(255) NULL"],
    ['users', 'terms_accepted_at', "ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL"],
    ['users', 'privacy_accepted_at', "ALTER TABLE users ADD COLUMN privacy_accepted_at DATETIME NULL"],
    ['users', 'phone_verified_at', "ALTER TABLE users ADD COLUMN phone_verified_at DATETIME NULL"],
    ['users', 'onboarding_step', "ALTER TABLE users ADD COLUMN onboarding_step VARCHAR(40) NULL DEFAULT 'start'"],
    ['users', 'risk_score', "ALTER TABLE users ADD COLUMN risk_score INT NULL"],
    ['users', 'risk_band', "ALTER TABLE users MODIFY risk_band VARCHAR(2) NULL"],
    ['users', 'account_type', "ALTER TABLE users ADD COLUMN account_type ENUM('persona','pyme') NOT NULL DEFAULT 'persona' AFTER role"],
    ['users', 'company_name', "ALTER TABLE users ADD COLUMN company_name VARCHAR(160) NULL AFTER account_type"],
    ['users', 'regret_requested_at', "ALTER TABLE users ADD COLUMN regret_requested_at DATETIME NULL"],
    ['users', 'closed_at', "ALTER TABLE users ADD COLUMN closed_at DATETIME NULL"],
    ['users', 'closure_reason', "ALTER TABLE users ADD COLUMN closure_reason VARCHAR(255) NULL"],
    ['users', 'fideicomiso_accepted_at', "ALTER TABLE users ADD COLUMN fideicomiso_accepted_at DATETIME NULL"],
    ['loans', 'tea', "ALTER TABLE loans ADD COLUMN tea DECIMAL(9,4) NULL AFTER annual_rate"],
    ['loans', 'cft_tna', "ALTER TABLE loans ADD COLUMN cft_tna DECIMAL(9,4) NULL AFTER tea"],
    ['loans', 'cft_tea', "ALTER TABLE loans ADD COLUMN cft_tea DECIMAL(9,4) NULL AFTER cft_tna"],
    ['loans', 'origination_fee_amount', "ALTER TABLE loans ADD COLUMN origination_fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER cft_tea"],
];

foreach ($alters as [$table, $col, $sql]) {
    if (!columnExists($pdo, $table, $col)) {
        $pdo->exec($sql);
        $msgs[] = "OK column {$table}.{$col}";
    } else {
        $msgs[] = "SKIP {$table}.{$col}";
    }
}

$tables = [
    'otp_codes' => "CREATE TABLE otp_codes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      channel ENUM('email','sms') NOT NULL,
      code_hash VARCHAR(255) NOT NULL,
      expires_at DATETIME NOT NULL,
      consumed_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_otp_user (user_id, channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'support_tickets' => "CREATE TABLE support_tickets (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NULL,
      name VARCHAR(120) NOT NULL,
      email VARCHAR(190) NOT NULL,
      subject VARCHAR(160) NOT NULL,
      message TEXT NOT NULL,
      status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'withdraw_requests' => "CREATE TABLE withdraw_requests (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      amount DECIMAL(14,2) NOT NULL,
      destination_cbu VARCHAR(22) NULL,
      destination_alias VARCHAR(40) NULL,
      destination_holder VARCHAR(160) NULL,
      status ENUM('pending','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
      admin_id INT UNSIGNED NULL,
      admin_notes VARCHAR(255) NULL,
      paid_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_wd_user (user_id, status),
      INDEX idx_wd_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'login_attempts' => "CREATE TABLE login_attempts (
      bucket CHAR(64) NOT NULL PRIMARY KEY,
      login_key VARCHAR(190) NOT NULL,
      ip VARCHAR(45) NOT NULL,
      attempts INT UNSIGNED NOT NULL DEFAULT 0,
      locked_until DATETIME NULL,
      updated_at DATETIME NOT NULL,
      INDEX idx_login_ip (ip),
      INDEX idx_login_locked (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    if (!tableExists($pdo, $name)) {
        $pdo->exec($sql);
        $msgs[] = "OK table {$name}";
    } else {
        $msgs[] = "SKIP table {$name}";
    }
}

@file_put_contents(CREDIMAX_ROOT . '/storage/INSTALL_LOCKED', date('c') . " migrate_production\n");
try {
    $pdo->exec('ALTER TABLE users MODIFY risk_band VARCHAR(2) NULL');
    $msgs[] = 'OK modify users.risk_band VARCHAR(2)';
} catch (Throwable $e) {
    $msgs[] = 'SKIP modify risk_band: ' . $e->getMessage();
}
$out = implode("\n", $msgs) . "\nDONE — borrá migrate_production.php en producción.\n";
echo $out;
@file_put_contents(CREDIMAX_ROOT . '/storage/logs/migrate.log', date('c') . "\n" . $out, FILE_APPEND);
