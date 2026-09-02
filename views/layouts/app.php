<?php
/** @var string $content */
/** @var string $title */
$user = auth_user();
$appName = \App\Core\App::config('app_name', 'Credimax');
$kyc = $user['kyc_status'] ?? 'pending';
$fonts = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@500;600;700;800&display=swap';
$nonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? $appName) ?> · <?= e($appName) ?></title>
  <meta name="theme-color" content="#0A2340">
  <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="<?= e($fonts) ?>" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-body">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="<?= e(url('/dashboard')) ?>">
        <span class="brand-mark" aria-hidden="true"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
        <span class="brand-text">Credimax</span>
      </a>
      <?php require CREDIMAX_ROOT . '/views/partials/nav-app.php'; ?>
      <div class="side-foot">
        <div class="id-chip"><i class="fa-solid fa-id-badge" style="margin-right:.4rem;opacity:.8"></i><?= e($user['credimax_id'] ?? '') ?></div>
        <form method="post" action="<?= e(url('/logout')) ?>">
          <?= csrf_field() ?>
          <button class="linkish" type="submit"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
        </form>
      </div>
    </aside>
    <div class="main">
      <header class="topbar">
        <button class="menu-btn" type="button" data-toggle-sidebar aria-label="Menú"><i class="fa-solid fa-bars"></i></button>
        <div class="topbar-user">
          <strong><?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></strong>
          <span class="muted"><?= e($title ?? '') ?> · KYC <?= e(status_label($kyc)) ?></span>
        </div>
        <div class="actions" style="margin-left:auto">
          <a class="btn btn-sm" href="<?= e(url('/notifications')) ?>"><i class="fa-solid fa-bell"></i> Alertas</a>
          <a class="btn btn-sm btn-accent" href="<?= e(url('/wallet/mp')) ?>"><i class="fa-solid fa-plus"></i> Cargar saldo</a>
        </div>
      </header>
      <?php if ($msg = flash('success')): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('info')): ?><div class="flash info"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('error')): ?><div class="flash err"><?= e($msg) ?></div><?php endif; ?>
      <main class="content">
        <?= $content ?>
      </main>
    </div>
  </div>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
  <script nonce="<?= e($nonce) ?>">
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= e(url('/service-worker.js')) ?>', { scope: '<?= e(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/') ?>' })
        .catch(function () {});
    });
  }
  </script>
</body>
</html>
