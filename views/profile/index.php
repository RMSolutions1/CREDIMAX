<?php
/** @var array $profile */
$mode = (string) ($profile['two_factor_mode'] ?? 'email_otp');
$totpActive = $mode === 'totp';
?>
<section class="page-head"><h1>Mi perfil</h1></section>

<section class="panel narrow">
  <ul class="kv">
    <li><span>Credimax ID</span><strong><?= e($profile['credimax_id']) ?></strong></li>
    <li><span>Email</span><strong><?= e($profile['email']) ?></strong></li>
    <li><span>KYC</span><strong><?= e(status_label($profile['kyc_status'])) ?></strong></li>
    <li>
      <span>2FA</span>
      <strong>
        <?php if ($totpActive): ?>
          <span class="badge" style="background:rgba(67,181,129,.18);color:#43B581">TOTP activo</span>
        <?php else: ?>
          <span class="badge" style="background:rgba(232,201,122,.18);color:#E8C97A"><?= e(mb_strtoupper($mode)) ?> por email</span>
        <?php endif; ?>
      </strong>
    </li>
  </ul>
  <form method="post" action="<?= e(url('/profile')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Teléfono</label>
    <input name="phone" value="<?= e($profile['phone'] ?? '') ?>">
    <label>DNI</label>
    <input name="dni" value="<?= e($profile['dni'] ?? '') ?>">
    <button class="btn btn-accent" type="submit">Guardar</button>
  </form>
</section>

<section class="panel narrow">
  <h2>Autenticación en dos pasos</h2>
  <p class="muted">
    Activar TOTP (Google Authenticator / Authy) aumenta la seguridad de retiros, ajustes y operaciones sensibles.
    Sin TOTP, los códigos se envían por email.
  </p>
  <?php if (!$totpActive): ?>
    <a class="btn btn-accent" href="<?= e(url('/otp/totp/setup')) ?>">Activar TOTP ahora</a>
  <?php else: ?>
    <form method="post" action="<?= e(url('/otp/totp/disable')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Ingresá tu código TOTP para desactivar</label>
      <input
        type="text"
        name="totp_code"
        inputmode="numeric"
        pattern="[0-9]{6}"
        maxlength="6"
        required
        placeholder="123456"
        autocomplete="one-time-code">
      <button class="btn" type="submit">Desactivar TOTP</button>
    </form>
  <?php endif; ?>
</section>
