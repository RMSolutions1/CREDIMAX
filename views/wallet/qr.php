<?php
/** @var array $wallet */
/** @var string $payload */
use App\Helpers\QrSvg;
$mpEnabled = false;
try {
    $mpEnabled = (new App\Services\MercadoPagoService())->isEnabled();
} catch (\Throwable) {
    $mpEnabled = false;
}
?>
<section class="page-head">
  <div>
    <h1>QR Credimax</h1>
    <p class="muted">CVU <?= e($wallet['cvu'] ?? '') ?> · Alias <?= e($wallet['alias'] ?? '') ?> · ID <?= e((string)(auth_user()['credimax_id'] ?? '')) ?></p>
  </div>
  <div class="actions">
    <?php if ($mpEnabled): ?>
      <a class="btn btn-accent" href="<?= e(url('/wallet/mp')) ?>">Cobrar con Mercado Pago</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($mpEnabled): ?>
<section class="panel" style="margin-bottom:1rem">
  <h2>QR de cobro Mercado Pago</h2>
  <p class="muted">Para cobrar desde fuera de Credimax (app Mercado Pago / tarjeta), generá un cobro con monto fijo. El dinero entra a la cuenta madre y se acredita en tu sub-cuenta.</p>
  <form method="post" action="<?= e(url('/wallet/mp/cobro')) ?>" class="form" style="max-width:420px">
    <?= csrf_field() ?>
    <label>Monto a cobrar</label>
    <input name="amount" required placeholder="5000" inputmode="decimal">
    <label>Concepto</label>
    <input name="title" maxlength="120" placeholder="Cobro Credimax">
    <button class="btn btn-accent" type="submit">Generar QR Mercado Pago</button>
  </form>
</section>
<?php endif; ?>

<div class="grid-2">
  <section class="panel qr-panel">
    <h2>Mi código P2P interno</h2>
    <div class="qr-box" aria-label="QR local Credimax"><?= QrSvg::render($payload, 5, 3) ?></div>
    <p class="muted">Solo para pagos entre usuarios Credimax (saldo interno).</p>
    <code class="payload" id="qr-payload"><?= e($payload) ?></code>
  </section>
  <section class="panel">
    <h2>Pagar con QR / CVU interno</h2>
    <p class="muted">Pegá el payload JSON, el token QR, el CVU o el alias de otro usuario Credimax.</p>
    <form method="post" action="<?= e(url('/wallet/qr/pay')) ?>" class="form">
      <?= csrf_field() ?><?= idem_field() ?>
      <label>Payload / token / CVU / alias</label>
      <textarea name="qr_payload" rows="4" required placeholder='{"v":1,"app":"credimax","token":"..."}'></textarea>
      <label>Monto</label>
      <input name="amount" required>
      <label>Nota</label>
      <input name="note">
      <button class="btn btn-accent" type="submit">Pagar</button>
    </form>
  </section>
</div>
