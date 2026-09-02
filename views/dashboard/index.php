<?php
/** @var array $wallet */
/** @var int $unread */
/** @var array|null $nextInstallment */
/** @var array $openLoans */
/** @var array $myLoans */
/** @var array $investments */
/** @var float $investedActive */
/** @var float $expectedReturn */
/** @var float $borrowedActive */
/** @var string $mode */
$showBorrow = $mode !== 'inversor';
$showInvest = $mode !== 'solicitante';
$first = auth_user()['first_name'] ?? '';
?>
<section class="page-head">
  <div>
    <h1>Hola, <?= e($first) ?></h1>
    <p class="muted">Tu centro de control: dinero, créditos e inversiones en un solo lugar.</p>
  </div>
  <div class="mode-switch" role="tablist" aria-label="Vista del panel">
    <a class="<?= $mode === 'ambos' || $mode === '' ? 'is-on' : '' ?>" href="<?= e(url('/dashboard')) ?>">Todo</a>
    <a class="<?= $mode === 'solicitante' ? 'is-on' : '' ?>" href="<?= e(url('/dashboard?modo=solicitante')) ?>">Créditos</a>
    <a class="<?= $mode === 'inversor' ? 'is-on' : '' ?>" href="<?= e(url('/dashboard?modo=inversor')) ?>">Inversiones</a>
  </div>
</section>

<?php if ((auth_user()['kyc_status'] ?? '') !== 'approved'): ?>
  <div class="banner warn">
    Completá la verificación de identidad para pedir o invertir.
    <a href="<?= e(url('/onboarding')) ?>">Continuar onboarding</a>
  </div>
<?php endif; ?>

<div class="money-card">
  <div class="money-card-top">
    <div>
      <span>Disponible en billetera</span>
      <div class="money-amount"><?= e(money($wallet['available_balance'] ?? 0)) ?></div>
    </div>
    <span class="badge" style="background:rgba(232,201,122,.18);color:#E8C97A">ARS</span>
  </div>
  <p class="money-card-meta">
    Alias <?= e($wallet['alias'] ?? '—') ?>
    · CVU <?= e($wallet['cvu'] ?? '—') ?>
    · Reservado <?= e(money($wallet['reserved_balance'] ?? 0)) ?>
  </p>
</div>

<div class="quick-actions">
  <a href="<?= e(url('/wallet/mp')) ?>">Cargar<small>Mercado Pago</small></a>
  <a href="<?= e(url('/wallet')) ?>">Retirar<small>A CBU / alias</small></a>
  <a href="<?= e(url('/loans/create')) ?>">Pedir<small>Nuevo crédito</small></a>
  <a href="<?= e(url('/marketplace')) ?>">Invertir<small>Mercado P2P</small></a>
</div>

<div class="stat-grid">
  <?php if ($showInvest): ?>
    <div class="stat"><span>Invertido activo</span><strong><?= e(money($investedActive ?? 0)) ?></strong></div>
    <div class="stat"><span>Retorno esperado</span><strong><?= e(money($expectedReturn ?? 0)) ?></strong></div>
  <?php endif; ?>
  <?php if ($showBorrow): ?>
    <div class="stat"><span>Créditos tomados</span><strong><?= e(money($borrowedActive ?? 0)) ?></strong></div>
    <div class="stat"><span>Próxima cuota</span><strong><?= $nextInstallment ? e(money((float)$nextInstallment['total_amount'] - (float)$nextInstallment['paid_amount'])) : '—' ?></strong></div>
  <?php endif; ?>
  <div class="stat"><span>Alertas</span><strong><?= (int) $unread ?></strong></div>
</div>

<?php if ($showInvest): ?>
<section class="panel dash-role">
  <div class="panel-head">
    <h2>Inversiones</h2>
    <div class="actions">
      <a class="btn btn-accent" href="<?= e(url('/marketplace')) ?>">Ir al mercado</a>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <h3>Tu portafolio</h3>
      <?php if (!$investments): ?>
        <p class="muted">Todavía no fondeaste créditos. Elegí por perfil AA–F y monto.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($investments as $inv): ?>
            <a class="list-item" href="<?= e(url('/loans/' . $inv['loan_id'])) ?>">
              <div>
                <strong><?= e($inv['loan_code']) ?></strong>
                <div class="muted"><?= e(status_label($inv['status'])) ?> · <?= e(status_label($inv['loan_status'])) ?></div>
              </div>
              <div class="right">
                <strong><?= e(money($inv['amount'])) ?></strong>
                <div class="muted"><?= e(number_format((float)$inv['annual_rate'], 1)) ?>% TNA · est. <?= e(money($inv['expected_return'] ?? 0)) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:.75rem"><a href="<?= e(url('/investments')) ?>">Ver todas las inversiones →</a></p>
      <?php endif; ?>
    </div>
    <div>
      <h3>Oportunidades abiertas</h3>
      <?php if (!$openLoans): ?>
        <p class="muted">No hay solicitudes abiertas por ahora.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($openLoans as $l): ?>
            <a class="list-item" href="<?= e(url('/loans/' . $l['id'])) ?>">
              <div>
                <strong><?= e($l['loan_code']) ?></strong>
                <div class="muted"><?= e($l['credimax_id']) ?> · Perfil <?= e($l['risk_band'] ?? '—') ?></div>
              </div>
              <div class="right">
                <strong><?= e(money($l['principal'])) ?></strong>
                <div class="muted"><?= e(number_format((float)$l['annual_rate'], 1)) ?>% TNA</div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($showBorrow): ?>
<section class="panel dash-role">
  <div class="panel-head">
    <h2>Tus créditos</h2>
    <div class="actions">
      <a class="btn btn-accent" href="<?= e(url('/loans/create')) ?>">Solicitar crédito</a>
      <a class="btn" href="<?= e(url('/simulador')) ?>">Simular</a>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <h3>En curso</h3>
      <?php if (!$myLoans): ?>
        <p class="muted">Todavía no publicaste solicitudes. Simulá y pedí el monto que necesitás.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($myLoans as $l): ?>
            <a class="list-item" href="<?= e(url('/loans/' . $l['id'])) ?>">
              <div>
                <strong><?= e($l['loan_code']) ?></strong>
                <div class="muted"><?= e(status_label($l['status'])) ?> · <?= (int)$l['term_months'] ?> meses</div>
              </div>
              <strong><?= e(money($l['principal'])) ?></strong>
            </a>
          <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:.75rem"><a href="<?= e(url('/loans')) ?>">Ver todos mis créditos →</a></p>
      <?php endif; ?>
    </div>
    <div>
      <h3>Próximo vencimiento</h3>
      <?php if (!$nextInstallment): ?>
        <p class="muted">No tenés cuotas pendientes.</p>
      <?php else: ?>
        <ul class="kv">
          <li><span>Crédito</span><strong><?= e($nextInstallment['loan_code']) ?></strong></li>
          <li><span>Vence</span><strong><?= e($nextInstallment['due_date']) ?></strong></li>
          <li><span>Estado</span><strong><?= e(status_label($nextInstallment['status'])) ?></strong></li>
          <li class="rate-highlight"><span>A pagar</span><strong><?= e(money((float)$nextInstallment['total_amount'] - (float)$nextInstallment['paid_amount'])) ?></strong></li>
        </ul>
        <a class="btn btn-accent" href="<?= e(url('/loans/' . $nextInstallment['loan_id'])) ?>">Pagar cuota</a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
