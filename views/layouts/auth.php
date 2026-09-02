<?php
/** @var string $content */
/** @var string $title */
$fonts = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@500;600;700;800&display=swap';
$nonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Credimax') ?></title>
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
<body class="auth-body">
  <aside class="auth-aside">
    <a class="brand" href="<?= e(url('/')) ?>">
      <span class="brand-mark" aria-hidden="true"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
      <span class="brand-text">Credimax</span>
    </a>
    <div>
      <h2>Tu dinero, tus créditos, tu criterio.</h2>
      <p>La plataforma argentina para pedir, invertir y gestionar créditos entre personas. En pesos. Con CFT a la vista.</p>
      <ul>
        <li><i class="fa-solid fa-money-bill-transfer gold"></i> Desembolso del 100% del monto pedido</li>
        <li><i class="fa-solid fa-wallet emerald"></i> Billetera con sub-cuenta Mercado Pago</li>
        <li><i class="fa-solid fa-fingerprint blue"></i> Identidad verificada y operatoria en ARS</li>
      </ul>
    </div>
    <p class="muted"><i class="fa-solid fa-scale-balanced"></i> PSCPP · No somos entidad financiera del BCRA</p>
  </aside>
  <div class="auth-wrap">
    <div class="auth-wrap-inner">
      <?php if ($msg = flash('success')): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('info')): ?><div class="flash info"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash('error')): ?><div class="flash err"><?= e($msg) ?></div><?php endif; ?>
      <div class="auth-card">
        <?= $content ?>
      </div>
    </div>
  </div>
  <script nonce="<?= e($nonce) ?>">
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= e(url('/service-worker.js')) ?>')
        .catch(function () {});
    });
  }
  </script>
</body>
</html>
