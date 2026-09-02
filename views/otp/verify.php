<?php
/** @var string $scope */
/** @var float $amount */
/** @var string $back */
?>
<section class="page-head"><h1>Verificación requerida</h1></section>
<section class="panel narrow">
  <p class="muted">Por seguridad, confirmá la operación con el código enviado o tu app TOTP.</p>
  <?php if ($amount > 0): ?>
    <p>Monto de la operación: <strong><?= money($amount) ?></strong></p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/otp/verify')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Código de 6 dígitos</label>
    <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" placeholder="123456">
    <button class="btn btn-accent" type="submit">Confirmar</button>
  </form>
  <form method="post" action="<?= e(url('/otp/resend')) ?>" class="form" style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn" type="submit">Reenviar código</button>
  </form>
  <p style="margin-top:1rem"><a href="<?= e($back) ?>">Cancelar y volver</a></p>
</section>
