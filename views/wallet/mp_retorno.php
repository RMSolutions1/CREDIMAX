<?php
/** @var string $status */
/** @var array|null $deposit */
/** @var array $wallet */

$state = $deposit['status'] ?? null;
if ($state === 'confirmed') {
    $headline = 'Saldo acreditado';
    $detail = 'Ya podés usar el dinero en tu billetera Credimax.';
} elseif ($state === 'cancelled') {
    $headline = 'El pago no se completó';
    $detail = 'Mercado Pago rechazó o canceló la operación. No se debitó nada.';
} elseif ($status === 'approved') {
    $headline = 'Pago aprobado, acreditando';
    $detail = 'Mercado Pago aprobó el pago. La acreditación se confirma en instantes.';
} else {
    $headline = 'Pago en proceso';
    $detail = 'Si elegiste efectivo o transferencia, el saldo se acredita cuando Mercado Pago confirme el pago.';
}
?>
<section class="page-head">
  <div>
    <h1><?= e($headline) ?></h1>
    <p class="muted"><?= e($detail) ?></p>
  </div>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/wallet')) ?>">Ir a mi billetera</a>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Saldo disponible</span><strong><?= e(money($wallet['available_balance'])) ?></strong></div>
  <?php if ($deposit): ?>
    <div class="stat"><span>Monto de la carga</span><strong><?= e(money($deposit['amount'])) ?></strong></div>
    <div class="stat"><span>Estado</span><strong><?= e(status_label((string) $deposit['status'])) ?></strong></div>
    <div class="stat"><span>Referencia</span><strong><?= e((string) $deposit['external_reference']) ?></strong></div>
  <?php endif; ?>
</div>

<section class="panel">
  <h2>¿No ves el saldo todavía?</h2>
  <p class="muted">
    La acreditación se dispara con la notificación de Mercado Pago, que puede demorar algunos
    segundos. Si pagaste en efectivo (Rapipago, Pago Fácil), la acreditación ocurre cuando el
    local reporta el pago, en general dentro de las 48 horas hábiles.
  </p>
  <p><a href="<?= e(url('/wallet/mp')) ?>">Ver mis movimientos con Mercado Pago →</a></p>
</section>
