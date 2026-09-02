<?php
declare(strict_types=1);

/**
 * Instalador web Credimax
 * Borrar o proteger esta carpeta tras instalar en producción.
 */

session_start();

$configPath = dirname(__DIR__) . '/config/config.php';
$already = is_file($configPath);
if ($already) {
    http_response_code(403);
    echo 'Instalador bloqueado: ya existe una configuración.';
    exit;
}
if (is_file(dirname(__DIR__) . '/storage/INSTALL_LOCKED')) {
    http_response_code(403);
    echo 'Instalador bloqueado (storage/INSTALL_LOCKED).';
    exit;
}

$error = null;
$success = null;
$step = (int) ($_GET['step'] ?? 1);

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$installCsrf = (string) $_SESSION['install_csrf'];

$isStrongPassword = static function (string $password): bool {
    return strlen($password) >= 8
        && (bool) preg_match('/[A-Z]/', $password)
        && (bool) preg_match('/[a-z]/', $password)
        && (bool) preg_match('/[0-9]/', $password);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminName = trim($_POST['admin_first'] ?? 'Admin');
    $adminLast = trim($_POST['admin_last'] ?? 'Credimax');

    try {
        $postedCsrf = (string) ($_POST['_token'] ?? '');
        if ($postedCsrf === '' || !hash_equals($installCsrf, $postedCsrf)) {
            throw new RuntimeException('Token de seguridad inválido. Recargá la página e intentá de nuevo.');
        }
        if ($appUrl === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('Completá URL, base de datos y usuario MySQL.');
        }
        if (!preg_match('#^https?://#i', $appUrl)) {
            throw new RuntimeException('La URL de la app debe comenzar con http:// o https://.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $dbHost)) {
            throw new RuntimeException('Host MySQL inválido.');
        }
        if ($dbPort < 1 || $dbPort > 65535) {
            throw new RuntimeException('Puerto MySQL inválido.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Nombre de base inválido (solo letras, números y _).');
        }
        if (!preg_match('/^[A-Za-z0-9_@.-]+$/', $dbUser)) {
            throw new RuntimeException('Usuario MySQL inválido.');
        }
        if (str_starts_with($appUrl, 'https://') && $dbPass === '') {
            throw new RuntimeException('En producción HTTPS la base de datos debe tener password.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email admin inválido.');
        }
        if (!$isStrongPassword($adminPass)) {
            throw new RuntimeException('La contraseña admin debe tener mín. 8 caracteres, mayúscula, minúscula y número.');
        }

        $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . str_replace('`', '``', $dbName) . '`');

        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('No se pudo leer database/schema.sql');
        }
        // Ejecutar sentencia por sentencia (hostings sin multi_query)
        $schema = preg_replace('/^--.*$/m', '', $schema);
        $parts = array_filter(array_map('trim', explode(';', $schema)));
        foreach ($parts as $sql) {
            if ($sql !== '') {
                $pdo->exec($sql);
            }
        }

        $credimaxId = 'CMX-ADMIN001';
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (credimax_id, email, password_hash, first_name, last_name, role, can_lend, can_borrow, kyc_status, status, email_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$credimaxId, $adminEmail, $hash, $adminName, $adminLast, 'admin', 1, 1, 'approved', 'active']);
        $adminId = (int) $pdo->lastInsertId();

        $qr = hash('sha256', random_bytes(32));
        $pdo->prepare('INSERT INTO wallets (user_id, balance, available_balance, reserved_balance, currency, qr_token, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$adminId, 0, 0, 0, 'ARS', $qr, 'active']);

        // Usuarios demo: solo permitidos si la URL no es HTTPS de producción.
        $isHttpsProd = str_starts_with($appUrl, 'https://');
        if (!empty($_POST['seed_demo'])) {
            if ($isHttpsProd) {
                throw new RuntimeException('Los usuarios demo no se pueden crear en una instalación HTTPS de producción.');
            }
            $demoPass = password_hash('Demo1234!', PASSWORD_DEFAULT);
            foreach ([
                ['CMX-LENDER01', 'inversor@credimax.test', 'Ana', 'Inversora'],
                ['CMX-BORROW01', 'solicitante@credimax.test', 'Luis', 'Solicitante'],
            ] as $d) {
                $pdo->prepare('INSERT INTO users (credimax_id, email, password_hash, first_name, last_name, role, can_lend, can_borrow, kyc_status, status) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$d[0], $d[1], $demoPass, $d[2], $d[3], 'user', 1, 1, 'approved', 'active']);
                $uid = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO wallets (user_id, balance, available_balance, reserved_balance, currency, qr_token, status) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$uid, 500000, 500000, 0, 'ARS', hash('sha256', random_bytes(16) . $uid), 'active']);
            }
        }

        $jwtSecret = bin2hex(random_bytes(32));
        $cronKey = bin2hex(random_bytes(24));
        $appKey = bin2hex(random_bytes(32));
        $mailFromHost = parse_url($appUrl, PHP_URL_HOST) ?: 'credimax.local';

        $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'app_name' => 'Credimax',
            'app_url' => $appUrl,
            'app_env' => $isHttpsProd ? 'production' : 'local',
            'app_debug' => !$isHttpsProd,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'locale' => 'es_AR',
            'currency' => 'ARS',
            'currency_symbol' => '$',
            'db' => [
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ],
            'security' => [
                'session_name' => 'CREDIMAXSESSID',
                'csrf_key' => 'credimax_csrf',
                'password_algo' => PASSWORD_DEFAULT,
                'login_max_attempts' => 8,
                'login_lock_minutes' => 15,
                'jwt_secret' => $jwtSecret,
                'cron_key' => $cronKey,
                'app_key' => $appKey,
                'install_locked' => true,
            ],
            'mercadopago' => [
                'enabled' => false,
                'site_id' => 'MLA',
                'access_token' => '',
                'public_key' => '',
                'client_id' => '',
                'client_secret' => '',
                'webhook_secret' => '',
                'redirect_uri' => '',
                'statement_descriptor' => 'CREDIMAX',
                'binary_mode' => false,
                'expiration_minutes' => 60,
                'charge_expiration_minutes' => 1440,
                'topup_fee_mode' => 'absorb',
                'charge_fee_pct' => 0.0,
                'max_installments' => 12,
                'excluded_payment_types' => [],
                'excluded_payment_methods' => [],
            ],
            'wallet' => [
                'min_deposit' => 100,
                'max_deposit' => 2000000,
                'min_withdraw' => 100,
                'max_withdraw' => 2000000,
                'min_transfer' => 50,
                'platform_fee_pct' => 1.5,
            ],
            'credit' => [
                'iva_pct' => 21.0,
                'rate_reference_amount' => 1000000.0,
                'rate_reference_months' => 12,
            ],
            'mail' => [
                'enabled' => $isHttpsProd,
                'from' => 'noreply@' . $mailFromHost,
                'from_name' => 'Credimax',
            ],
            'uploads' => [
                'max_mb' => 5,
                'allowed' => ['jpg', 'jpeg', 'png', 'pdf', 'webp'],
            ],
        ], true) . ";\n";

        if (!is_dir(dirname($configPath))) {
            mkdir(dirname($configPath), 0755, true);
        }
        if (file_put_contents($configPath, $config) === false) {
            throw new RuntimeException('No se pudo escribir config/config.php. Revisá permisos.');
        }

        @file_put_contents(dirname(__DIR__) . '/storage/logs/.gitkeep', '');
        @mkdir(dirname(__DIR__) . '/storage/uploads/kyc', 0755, true);
        @file_put_contents(dirname(__DIR__) . '/storage/INSTALL_LOCKED', date('c') . " install\n");

        $success = 'Instalación completada. Ingresá con tu admin. El instalador quedó bloqueado; no subas usuarios demo a producción.';
        $already = true;
        $step = 3;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$detectedUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Instalar Credimax</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#0b1f1a; --card:#123028; --ink:#f3f7f5; --muted:#a7c0b6; --accent:#d4a017; --line:rgba(255,255,255,.08); }
    *{box-sizing:border-box} body{margin:0;font-family:'DM Sans',sans-serif;background:radial-gradient(1200px 600px at 10% -10%,#1a4a3c,transparent),var(--bg);color:var(--ink);min-height:100vh;display:grid;place-items:center;padding:24px}
    .box{width:min(560px,100%);background:linear-gradient(180deg,rgba(255,255,255,.04),transparent),var(--card);border:1px solid var(--line);border-radius:18px;padding:32px;box-shadow:0 30px 80px rgba(0,0,0,.35)}
    h1{font-family:Fraunces,serif;margin:0 0 8px;font-size:2rem}
    p{color:var(--muted);line-height:1.5}
    label{display:block;font-size:.85rem;margin:14px 0 6px;color:var(--muted)}
    input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--line);background:#0c221c;color:var(--ink);font:inherit}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    button{margin-top:20px;width:100%;border:0;border-radius:999px;padding:14px 18px;font-weight:700;background:var(--accent);color:#1a1400;cursor:pointer}
    .alert{padding:12px 14px;border-radius:10px;margin:12px 0}
    .err{background:#3a1515;color:#ffd0d0}.ok{background:#143528;color:#c9f7df}
    .check{display:flex;gap:10px;align-items:center;margin-top:16px;color:var(--muted)}
  </style>
</head>
<body>
  <div class="box">
    <h1>Credimax</h1>
    <p>Instalador de producción · PHP + MySQL · listo para FTP</p>
    <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if (!$already): ?>
    <form method="post">
      <input type="hidden" name="_token" value="<?= htmlspecialchars($installCsrf) ?>">
      <label>URL pública de la app</label>
      <input name="app_url" required value="<?= htmlspecialchars($detectedUrl) ?>">
      <div class="row">
        <div>
          <label>Host MySQL</label>
          <input name="db_host" value="localhost" required>
        </div>
        <div>
          <label>Puerto</label>
          <input name="db_port" value="3306" required>
        </div>
      </div>
      <label>Nombre de la base</label>
      <input name="db_name" value="credimax" required>
      <div class="row">
        <div>
          <label>Usuario DB</label>
          <input name="db_user" required>
        </div>
        <div>
          <label>Password DB</label>
          <input name="db_pass" type="password">
        </div>
      </div>
      <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">
      <label>Email administrador</label>
      <input name="admin_email" type="email" required>
      <div class="row">
        <div>
          <label>Nombre</label>
          <input name="admin_first" value="Admin">
        </div>
        <div>
          <label>Apellido</label>
          <input name="admin_last" value="Credimax">
        </div>
      </div>
      <label>Password administrador (mín. 8, mayúscula, minúscula y número)</label>
      <input name="admin_pass" type="password" minlength="8" required autocomplete="new-password">
      <label class="check"><input type="checkbox" name="seed_demo" value="1"> Crear usuarios demo (solo entornos locales; password Demo1234!)</label>
      <button type="submit">Instalar Credimax</button>
    </form>
    <?php else: ?>
      <p><a href="../" style="color:var(--accent)">Ir a la aplicación</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
