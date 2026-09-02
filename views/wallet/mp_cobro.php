<?php
/** @var array $charge */
$link = (string) ($charge['init_point'] ?? '');
$publicUrl = url('/cobro/' . $charge['code']);
?>
<section class="page-head">
  <div>
    <h1>Cobro <?= e($charge['title']) ?></h1>
    <p class="muted">
      <?= e(money($charge['amount'])) ?> · Código <code><?= e($charge['code']) ?></code>
      · Estado <strong><?= e(status_label((string) $charge['status'])) ?></strong>
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/wallet/mp')) ?>">Volver</a>
  </div>
</section>

<div class="grid-2">
  <section class="panel qr-panel">
    <h2>QR para cobrar</h2>
    <?php if ($link !== ''): ?>
      <div class="qr-box"><?= App\Helpers\QrSvg::render($link, 6, 4) ?></div>
      <p class="muted">Quien te paga escanea este QR con la cámara o la app de Mercado Pago.</p>
    <?php else: ?>
      <p class="muted">Este cobro no tiene link de pago asociado.</p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Compartir</h2>
    <p class="muted">Link público del cobro:</p>
    <p><code><?= e($publicUrl) ?></code></p>
    <?php if ($link !== ''): ?>
      <p style="margin-top:12px">
        <a class="btn btn-accent" href="<?= e($link) ?>" target="_blank" rel="noopener">Abrir en Mercado Pago</a>
      </p>
    <?php endif; ?>

    <?php if ($charge['status'] === 'open'): ?>
      <form method="post" action="<?= e(url('/wallet/mp/cobro/' . $charge['id'] . '/cancelar')) ?>" style="margin-top:16px">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Cancelar cobro</button>
      </form>
    <?php endif; ?>

    <?php if ($charge['status'] === 'paid'): ?>
      <p class="muted" style="margin-top:16px">
        Pagado el <?= e((string) $charge['paid_at']) ?>.
        Pago Mercado Pago <code><?= e((string) $charge['paid_payment_id']) ?></code>.
        <?php if ((float) $charge['fee_pct'] > 0): ?>
          Se aplicó una comisión de plataforma del <?= e((string) $charge['fee_pct']) ?>%.
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($charge['expires_at']) && $charge['status'] === 'open'): ?>
      <p class="muted" style="margin-top:12px">Vence el <?= e((string) $charge['expires_at']) ?>.</p>
    <?php endif; ?>
  </section>
</div>
