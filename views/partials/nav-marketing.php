<?php
/** Navegación pública Credimax */
$here = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string) (\App\Core\App::config('app_url', '')), '/');
$path = $here;
if ($base !== '') {
    $basePath = parse_url($base, PHP_URL_PATH) ?: '';
    if ($basePath && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }
}
$path = '/' . ltrim($path, '/');
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}
?>
<header class="m-nav">
  <a class="brand" href="<?= e(url('/')) ?>">
    <span class="brand-mark" aria-hidden="true"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
    <span class="brand-text">Credimax</span>
  </a>

  <button class="m-nav-toggle" type="button" data-toggle-mnav aria-label="Abrir menú" aria-expanded="false">☰</button>

  <nav class="m-nav-main" data-mnav>
    <div class="m-dd">
      <button class="m-dd-btn" type="button" aria-expanded="false"><i class="fa-solid fa-hand-holding-dollar" style="color:var(--gold-2)"></i>&nbsp;Créditos</button>
      <div class="m-dd-panel">
        <a href="<?= e(url('/pedir-credito')) ?>"><i class="fa-solid fa-bullseye"></i> Pedir un crédito</a>
        <a href="<?= e(url('/simulador')) ?>"><i class="fa-solid fa-calculator"></i> Simulador de cuota</a>
        <a href="<?= e(url('/tasas')) ?>"><i class="fa-solid fa-percent"></i> Tasas y CFT</a>
        <a href="<?= e(url('/pyme')) ?>"><i class="fa-solid fa-building"></i> Crédito PyME</a>
        <a href="<?= e(url('/requisitos')) ?>"><i class="fa-solid fa-list-check"></i> Requisitos</a>
      </div>
    </div>

    <div class="m-dd">
      <button class="m-dd-btn" type="button" aria-expanded="false"><i class="fa-solid fa-chart-line" style="color:var(--brand)"></i>&nbsp;Invertir</button>
      <div class="m-dd-panel">
        <a href="<?= e(url('/invertir')) ?>"><i class="fa-solid fa-sack-dollar"></i> Ser inversor</a>
        <a href="<?= e(url('/simulador-inversion')) ?>"><i class="fa-solid fa-coins"></i> Simulador de retorno</a>
        <a href="<?= e(url('/marketplace')) ?>"><i class="fa-solid fa-store"></i> Mercado P2P</a>
        <a href="<?= e(url('/estadisticas')) ?>"><i class="fa-solid fa-chart-simple"></i> Estadísticas</a>
      </div>
    </div>

    <div class="m-dd">
      <button class="m-dd-btn" type="button" aria-expanded="false"><i class="fa-solid fa-cube" style="color:var(--brand-3)"></i>&nbsp;Plataforma</button>
      <div class="m-dd-panel">
        <a href="<?= e(url('/como-funciona')) ?>"><i class="fa-solid fa-book-open"></i> Cómo funciona</a>
        <a href="<?= e(url('/por-que-credimax')) ?>"><i class="fa-solid fa-star"></i> Por qué Credimax</a>
        <a href="<?= e(url('/seguridad')) ?>"><i class="fa-solid fa-shield-halved"></i> Seguridad</a>
        <a href="<?= e(url('/nosotros')) ?>"><i class="fa-solid fa-users"></i> Nosotros</a>
        <a href="<?= e(url('/costos')) ?>"><i class="fa-solid fa-tags"></i> Costos</a>
      </div>
    </div>

    <div class="m-dd">
      <button class="m-dd-btn" type="button" aria-expanded="false"><i class="fa-solid fa-circle-question" style="color:var(--gold-2)"></i>&nbsp;Ayuda</button>
      <div class="m-dd-panel">
        <a href="<?= e(url('/ayuda')) ?>"><i class="fa-solid fa-lightbulb"></i> Centro de ayuda</a>
        <a href="<?= e(url('/faq')) ?>"><i class="fa-solid fa-circle-question"></i> Preguntas frecuentes</a>
        <a href="<?= e(url('/contacto')) ?>"><i class="fa-solid fa-paper-plane"></i> Contacto</a>
        <a href="<?= e(url('/legales/cumplimiento')) ?>"><i class="fa-solid fa-gavel"></i> Marco regulatorio</a>
      </div>
    </div>
  </nav>

  <div class="m-nav-actions">
    <?php if (auth_user()): ?>
      <a class="btn btn-accent" href="<?= e(url('/dashboard')) ?>"><i class="fa-solid fa-gauge-high"></i> Ir a mi cuenta</a>
    <?php else: ?>
      <a class="btn" href="<?= e(url('/login')) ?>"><i class="fa-solid fa-arrow-right-to-bracket"></i> Ingresar</a>
      <a class="btn btn-accent" href="<?= e(url('/register')) ?>"><i class="fa-solid fa-user-plus"></i> Abrir cuenta</a>
    <?php endif; ?>
  </div>
</header>
