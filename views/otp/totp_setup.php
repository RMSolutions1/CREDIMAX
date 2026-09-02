<?php
/** @var string $secret */
/** @var string $otpauth */
?>
<section class="page-head"><h1>Activar autenticador TOTP</h1></section>
<section class="panel narrow">
  <p class="muted">Escaneá este secreto con Google Authenticator, Authy o similar.</p>
  <p><strong>Secreto:</strong> <code><?= e($secret) ?></code></p>
  <p class="muted" style="word-break:break-all"><strong>URI:</strong> <?= e($otpauth) ?></p>
  <form method="post" action="<?= e(url('/otp/totp/confirm')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Ingresá el código de 6 dígitos para confirmar</label>
    <input name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
    <button class="btn btn-accent" type="submit">Activar TOTP</button>
  </form>
</section>
