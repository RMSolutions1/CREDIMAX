<?php
/** @var array|null $user */
$user = $user ?? auth_user();
$icon = static function (string $path): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="' . $path . '" stroke-linecap="round" stroke-linejoin="round"/></svg>';
};
?>
<nav class="side-nav" data-side-nav>
  <div class="nav-group open">
    <button class="nav-group-btn" type="button">Operar</button>
    <div class="nav-group-panel">
      <a href="<?= e(url('/dashboard')) ?>"><?= $icon('M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10') ?>Inicio</a>
      <a href="<?= e(url('/wallet')) ?>"><?= $icon('M3 7h18v12H3zM3 11h18M16 15h2') ?>Billetera</a>
      <a href="<?= e(url('/wallet/mp')) ?>"><?= $icon('M12 3v18M5 8h14M5 16h14') ?>Cargar / cobrar</a>
      <a href="<?= e(url('/marketplace')) ?>"><?= $icon('M4 6h16M4 12h16M4 18h10') ?>Mercado P2P</a>
      <a href="<?= e(url('/loans/create')) ?>"><?= $icon('M12 5v14M5 12h14') ?>Pedir crédito</a>
      <a href="<?= e(url('/loans')) ?>"><?= $icon('M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01') ?>Mis créditos</a>
      <a href="<?= e(url('/investments')) ?>"><?= $icon('M4 19V5M4 19h16M8 15l3-4 3 2 4-6') ?>Inversiones</a>
    </div>
  </div>

  <div class="nav-group open">
    <button class="nav-group-btn" type="button">Cuenta</button>
    <div class="nav-group-panel">
      <a href="<?= e(url('/wallet/qr')) ?>"><?= $icon('M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM15 15h2v2h-2zM19 15h1v1h-1zM15 19h1v1h-1zM19 19h1v1h-1z') ?>Mi QR</a>
      <a href="<?= e(url('/banking')) ?>"><?= $icon('M3 10l9-7 9 7v10H3z') ?>CVU y alias</a>
      <a href="<?= e(url('/kyc')) ?>"><?= $icon('M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0') ?>Identidad</a>
      <a href="<?= e(url('/profile')) ?>"><?= $icon('M12 12a4 4 0 100-8 4 4 0 000 8zM6 21v-1a6 6 0 0112 0v1') ?>Perfil</a>
      <a href="<?= e(url('/notifications')) ?>"><?= $icon('M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0') ?>Alertas</a>
    </div>
  </div>

  <div class="nav-group">
    <button class="nav-group-btn" type="button">Más</button>
    <div class="nav-group-panel">
      <a href="<?= e(url('/simulador')) ?>"><i class="fa-solid fa-calculator"></i> Simulador</a>
      <a href="<?= e(url('/tasas')) ?>"><i class="fa-solid fa-percent"></i> Tasas y CFT</a>
      <a href="<?= e(url('/ayuda')) ?>"><i class="fa-solid fa-circle-question"></i> Ayuda</a>
      <a href="<?= e(url('/onboarding')) ?>"><i class="fa-solid fa-person-walking-arrow-right"></i> Onboarding</a>
      <a href="<?= e(url('/funds')) ?>"><i class="fa-solid fa-sack-dollar"></i> Fondos / mandato</a>
      <a href="<?= e(url('/banking/api-keys')) ?>"><i class="fa-solid fa-key"></i> API</a>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="nav-group open">
    <button class="nav-group-btn" type="button">Administración</button>
    <div class="nav-group-panel">
      <a href="<?= e(url('/admin')) ?>"><i class="fa-solid fa-gauge-high"></i> Panel admin</a>
      <a href="<?= e(url('/admin/funds')) ?>"><i class="fa-solid fa-building-columns"></i> Tesorería</a>
      <a href="<?= e(url('/admin/mercadopago')) ?>"><i class="fa-solid fa-credit-card"></i> Mercado Pago</a>
      <a href="<?= e(url('/admin/kyc')) ?>"><i class="fa-solid fa-id-card"></i> Revisar KYC</a>
      <a href="<?= e(url('/admin/users')) ?>"><i class="fa-solid fa-users-gear"></i> Usuarios</a>
      <a href="<?= e(url('/admin/loans')) ?>"><i class="fa-solid fa-hand-holding-dollar"></i> Créditos</a>
      <a href="<?= e(url('/admin/products')) ?>"><i class="fa-solid fa-table-list"></i> Productos</a>
    </div>
  </div>
  <?php endif; ?>
</nav>
