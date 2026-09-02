<?php
/** @var array $stats */
?>
<section class="page-head">
  <div>
    <h1>Dashboard admin</h1>
    <p class="muted">Control operativo de Credimax: usuarios, KYC, créditos y tesorería.</p>
  </div>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/admin/funds')) ?>">Tesorería</a>
    <a class="btn" href="<?= e(url('/admin/mercadopago')) ?>">Mercado Pago</a>
    <a class="btn" href="<?= e(url('/admin/kyc')) ?>">KYC</a>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Usuarios</span><strong><?= (int)$stats['users'] ?></strong></div>
  <div class="stat"><span>Créditos activos</span><strong><?= (int)$stats['loans_active'] ?></strong></div>
  <div class="stat"><span>En mercado</span><strong><?= (int)$stats['loans_open'] ?></strong></div>
  <div class="stat"><span>KYC pendientes</span><strong><?= (int)$stats['kyc_pending'] ?></strong></div>
  <div class="stat"><span>Volumen créditos</span><strong><?= e(money($stats['volume'])) ?></strong></div>
  <div class="stat"><span>Saldo billeteras</span><strong><?= e(money($stats['wallet_total'])) ?></strong></div>
</div>

<div class="grid-2">
  <section class="panel">
    <h2>Fondos y tesorería</h2>
    <p class="muted">Confirmar depósitos, pagar retiros, ajustar saldos y capital propio.</p>
    <div class="actions">
      <a class="btn btn-accent" href="<?= e(url('/admin/funds')) ?>">Abrir tesorería</a>
    </div>
  </section>
  <section class="panel">
    <h2>Operaciones</h2>
    <form method="post" action="<?= e(url('/admin/mark-overdue')) ?>" style="margin-bottom:12px">
      <?= csrf_field() ?>
      <button class="btn" type="submit">Marcar cuotas vencidas</button>
    </form>
    <div class="actions">
      <a class="btn" href="<?= e(url('/admin/kyc')) ?>">Revisar KYC</a>
      <a class="btn" href="<?= e(url('/admin/users')) ?>">Usuarios</a>
      <a class="btn" href="<?= e(url('/admin/loans')) ?>">Créditos</a>
      <a class="btn" href="<?= e(url('/admin/products')) ?>">Productos</a>
    </div>
  </section>
</div>

<section class="panel" style="margin-top:1rem">
  <h2>Accesos rápidos</h2>
  <div class="actions">
    <a class="btn" href="<?= e(url('/marketplace')) ?>">Ver mercado</a>
    <a class="btn" href="<?= e(url('/estadisticas')) ?>">Estadísticas públicas</a>
    <a class="btn" href="<?= e(url('/tasas')) ?>">Tasas</a>
  </div>
</section>
