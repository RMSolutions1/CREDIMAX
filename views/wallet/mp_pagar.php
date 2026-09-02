<?php
/** @var array $charge */
$link = (string) ($charge['init_point'] ?? '');
$expired = !empty($charge['expires_at']) && strtotime((string) $charge['expires_at']) < time();
$payable = $charge['status'] === 'open' && $link !== '' && !$expired;
$holder = trim(($charge['first_name'] ?? '') . ' ' . ($charge['last_name'] ?? ''));
?>
<section class="page-head">
  <div>
    <h1>Pagar a <?= e($holder !== '' ? $holder : (string) $charge['credimax_id']) ?></h1>
    <p class="muted"><?= e($charge['title']) ?> · <?= e((string) $charge['credimax_id']) ?></p>
  </div>
</section>

<div class="grid-2">
  <section class="panel">
    <h2><?= e(money($charge['amount'])) ?></h2>
    <?php if ($payable): ?>
      <p class="muted">Pagás de forma segura dentro de Mercado Pago. No necesitás cuenta en Credimax.</p>
      <p style="margin-top:16px">
        <a class="btn btn-accent" href="<?= e($link) ?>">Pagar con Mercado Pago</a>
      </p>
    <?php elseif ($charge['status'] === 'paid'): ?>
      <p class="muted">Este cobro ya fue pagado el <?= e((string) $charge['paid_at']) ?>.</p>
    <?php elseif ($charge['status'] === 'cancelled'): ?>
      <p class="muted">Este cobro fue cancelado por quien lo generó.</p>
    <?php else: ?>
      <p class="muted">Este cobro ya no está disponible: venció o fue dado de baja.</p>
    <?php endif; ?>
    <?php if (!empty($charge['note'])): ?>
      <p class="muted" style="margin-top:12px"><?= e((string) $charge['note']) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($payable): ?>
    <section class="panel qr-panel">
      <h2>O escaneá el QR</h2>
      <div class="qr-box"><?= App\Helpers\QrSvg::render($link, 6, 4) ?></div>
    </section>
  <?php endif; ?>
</div>
