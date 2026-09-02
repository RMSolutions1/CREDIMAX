<?php
/** @var string $content */
/** @var string $title */
/** @var string|null $metaDescription */
$appUrl = rtrim((string) \App\Core\App::config('app_url', ''), '/');
$rawTitle = $title ?? null;
$brand = \App\Core\App::config('app_name', 'Credimax');
$defaultTitle = 'Créditos e inversiones P2P en Argentina · ' . $brand;
$pageTitle = $rawTitle ? ($rawTitle . ' · ' . $brand) : $defaultTitle;
$desc = $metaDescription
    ?? $brand . ' es la plataforma argentina de créditos e inversiones entre personas. Desembolso 100%, CFT transparente, fondeo con decisión del inversor. Billetera virtual, cuotas claras y gestión en pesos argentinos.';
$canonical = $appUrl . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$ogImage = $appUrl . '/assets/img/logo.svg';
$fonts = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@500;600;700;800&display=swap';
$nonce = csp_nonce();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <meta name="theme-color" content="#0A2340">
  <meta name="geo.region" content="AR">
  <meta name="geo.placename" content="Argentina">
  <meta name="author" content="<?= e($brand) ?>">
  <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= e(asset('img/logo.svg')) ?>">
  <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="es_AR">
  <meta property="og:site_name" content="<?= e($brand) ?>">
  <meta property="og:title" content="<?= e($pageTitle) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta property="og:image" content="<?= e($ogImage) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:type" content="image/svg+xml">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($pageTitle) ?>">
  <meta name="twitter:description" content="<?= e($desc) ?>">
  <meta name="twitter:image" content="<?= e($ogImage) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="<?= e($fonts) ?>" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script type="application/ld+json" nonce="<?= e($nonce) ?>">
  <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Organization',
      'name' => 'Credimax',
      'url' => $appUrl !== '' ? $appUrl : null,
      'logo' => $ogImage,
      'description' => 'Plataforma argentina de créditos e inversiones entre personas (PSCPP).',
      'areaServed' => 'AR',
      'availableLanguage' => 'es-AR',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
  </script>
</head>
<body class="marketing-body">
  <a class="skip-link" href="#main">Saltar al contenido</a>
  <?php require CREDIMAX_ROOT . '/views/partials/nav-marketing.php'; ?>
  <?php if ($msg = flash('success')): ?><div class="flash ok" style="margin:1rem clamp(1rem,4vw,3rem)"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="flash err" style="margin:1rem clamp(1rem,4vw,3rem)"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('info')): ?><div class="flash info" style="margin:1rem clamp(1rem,4vw,3rem)"><?= e($msg) ?></div><?php endif; ?>
  <main id="main">
    <?= $content ?>
  </main>
  <footer class="m-foot-full">
    <div class="foot-grid">
      <div>
        <a class="brand" href="<?= e(url('/')) ?>">
          <span class="brand-mark" aria-hidden="true"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
          <span class="brand-text"><?= e($brand) ?></span>
        </a>
        <p class="muted">
          <i class="fa-solid fa-flag-argentina" style="color:var(--gold-2)"></i>&nbsp; Plataforma argentina de créditos e inversiones P2P. En pesos. Con CFT transparente a la vista.
        </p>
        <p style="margin:.6rem 0 0;line-height:1.9">
          <a href="<?= e(url('/contacto')) ?>"><i class="fa-solid fa-envelope"></i> soporte@credimax.com.ar</a><br>
          <a href="tel:+541100000000"><i class="fa-solid fa-phone"></i> +54 11 0000-0000</a><br>
          <span class="muted"><i class="fa-solid fa-location-dot"></i> Ciudad Autónoma de Buenos Aires · Argentina</span>
        </p>
        <p style="margin-top:.9rem">
          <a class="btn btn-sm" style="padding:.3rem .55rem;font-size:.75rem" href="<?= e(url('/mapa-del-sitio')) ?>"><i class="fa-solid fa-sitemap"></i> Mapa del sitio</a>
          <a class="btn btn-sm" style="padding:.3rem .55rem;font-size:.75rem" href="<?= e(url('/sitemap.xml')) ?>"><i class="fa-solid fa-file-lines"></i> sitemap.xml</a>
        </p>
      </div>
      <div>
        <h4><i class="fa-solid fa-credit-card" style="color:var(--gold-2);margin-right:.35rem"></i>Producto</h4>
        <a href="<?= e(url('/pedir-credito')) ?>"><i class="fa-solid fa-hand-holding-dollar"></i> Pedir crédito</a>
        <a href="<?= e(url('/invertir')) ?>"><i class="fa-solid fa-chart-line"></i> Invertir</a>
        <a href="<?= e(url('/por-que-credimax')) ?>"><i class="fa-solid fa-star"></i> Por qué Credimax</a>
        <a href="<?= e(url('/pyme')) ?>"><i class="fa-solid fa-building"></i> PyME</a>
        <a href="<?= e(url('/simulador')) ?>"><i class="fa-solid fa-calculator"></i> Simulador crédito</a>
        <a href="<?= e(url('/simulador-inversion')) ?>"><i class="fa-solid fa-coins"></i> Simulador inversión</a>
        <a href="<?= e(url('/estadisticas')) ?>"><i class="fa-solid fa-chart-simple"></i> Estadísticas</a>
        <a href="<?= e(url('/costos')) ?>"><i class="fa-solid fa-tags"></i> Costos</a>
      </div>
      <div>
        <h4><i class="fa-solid fa-circle-info" style="color:var(--gold-2);margin-right:.35rem"></i>Ayuda</h4>
        <a href="<?= e(url('/como-funciona')) ?>"><i class="fa-solid fa-book-open"></i> Cómo funciona</a>
        <a href="<?= e(url('/faq')) ?>"><i class="fa-solid fa-circle-question"></i> Preguntas frecuentes</a>
        <a href="<?= e(url('/contacto')) ?>"><i class="fa-solid fa-paper-plane"></i> Contacto</a>
        <a href="<?= e(url('/seguridad')) ?>"><i class="fa-solid fa-shield-halved"></i> Seguridad</a>
        <a href="<?= e(url('/requisitos')) ?>"><i class="fa-solid fa-list-check"></i> Requisitos</a>
        <a href="<?= e(url('/tasas')) ?>"><i class="fa-solid fa-percent"></i> Tasas</a>
        <a href="<?= e(url('/legales/arrepentimiento')) ?>"><i class="fa-solid fa-rotate-left"></i> Botón de arrepentimiento</a>
        <a href="<?= e(url('/legales/baja')) ?>"><i class="fa-solid fa-user-minus"></i> Botón de baja</a>
        <a href="<?= e(url('/legales/usuario-financiero')) ?>"><i class="fa-solid fa-user-tie"></i> Usuario financiero</a>
      </div>
      <div>
        <h4><i class="fa-solid fa-scale-balanced" style="color:var(--gold-2);margin-right:.35rem"></i>Legales & cumplimiento</h4>
        <a href="<?= e(url('/legales/terminos')) ?>"><i class="fa-solid fa-file-signature"></i> Términos y condiciones</a>
        <a href="<?= e(url('/legales/adhesion')) ?>"><i class="fa-solid fa-file-contract"></i> Contrato de adhesión</a>
        <a href="<?= e(url('/legales/fideicomiso')) ?>"><i class="fa-solid fa-scale-unbalanced-flip"></i> Fideicomiso PSCPP</a>
        <a href="<?= e(url('/legales/cumplimiento')) ?>"><i class="fa-solid fa-gavel"></i> Marco regulatorio</a>
        <a href="<?= e(url('/legales/privacidad')) ?>"><i class="fa-solid fa-user-lock"></i> Política de privacidad</a>
        <a href="<?= e(url('/legales/cookies')) ?>"><i class="fa-solid fa-cookie-bite"></i> Política de cookies</a>
        <a href="<?= e(url('/legales/defensa-consumidor')) ?>"><i class="fa-solid fa-hand-holding-hand"></i> Defensa del Consumidor</a>
        <a href="<?= e(url('/legales/pep')) ?>"><i class="fa-solid fa-id-card"></i> Política PEPs / UIF</a>
        <a href="<?= e(url('/legales/manual')) ?>"><i class="fa-solid fa-book"></i> Manual de usuario</a>
      </div>
    </div>
    <p class="foot-note muted">
      <i class="fa-solid fa-circle-info" style="color:var(--gold-2);margin-right:.35rem"></i>
      <strong>Disclaimer regulatorio:</strong>
      <?= e($brand) ?> es una plataforma tecnológica de créditos entre particulares (modelo PSCPP).
      No está autorizada a operar como entidad financiera por el BCRA.
      No asume el riesgo crediticio, no garantiza el cobro entre inversores y tomadores,
      y no cuenta con el Seguro de Depósitos Bancarios (Ley 24.485).
      <a href="<?= e(url('/legales/cumplimiento')) ?>">Conocé el marco regulatorio completo</a>.
      Defensa de las y los Consumidores ·
      <a href="https://www.argentina.gob.ar/produccion/defensadelconsumidor/formulario" target="_blank" rel="noopener noreferrer">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Formulario oficial
      </a>.
      © <?= date('Y') ?> <?= e($brand) ?>. Todos los derechos reservados.
    </p>
  </footer>

  <div class="sticky-cta" data-sticky-cta hidden>
    <div class="sticky-cta-inner">
      <span><i class="fa-solid fa-calculator-simple" style="color:var(--gold-2);margin-right:.4rem"></i>Simulá gratis · Desembolso 100% · CFT transparente</span>
      <div class="sticky-cta-actions">
        <a class="btn btn-accent" href="<?= e(url('/simulador')) ?>"><i class="fa-solid fa-sliders"></i> Simular</a>
        <a class="btn" href="<?= e(url('/register')) ?>"><i class="fa-solid fa-user-plus"></i> Abrir cuenta</a>
      </div>
    </div>
  </div>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
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
