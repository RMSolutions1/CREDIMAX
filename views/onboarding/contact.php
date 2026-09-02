<?php
/** @var array $profile */
?>
<section class="page-head"><h1>Verificación de contacto</h1><p class="muted">Paso 2 de 5 · Código por email (SMS vía cola operativa)</p></section>
<section class="panel narrow">
  <ul class="kv">
    <li><span>Email</span><strong><?= e($profile['email']) ?> <?= !empty($profile['email_verified_at']) ? '✓' : '' ?></strong></li>
    <li><span>Celular</span><strong><?= e($profile['phone'] ?? '—') ?> <?= !empty($profile['phone_verified_at']) ? '✓' : '' ?></strong></li>
  </ul>
  <form method="post" action="<?= e(url('/onboarding/otp/send')) ?>" class="form inline-form">
    <?= csrf_field() ?>
    <select name="channel"><option value="email">Email</option><option value="sms">SMS/Celular</option></select>
    <button class="btn" type="submit">Enviar código</button>
  </form>
  <form method="post" action="<?= e(url('/onboarding/otp/verify')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Canal verificado</label>
    <select name="channel"><option value="email">Email</option><option value="sms">SMS/Celular</option></select>
    <label>Código de 6 dígitos</label>
    <input name="code" required maxlength="6" pattern="[0-9]{6}">
    <button class="btn btn-accent" type="submit">Verificar</button>
  </form>
  <p class="muted">El código se envía por email (o cola SMS). No se muestra en pantalla.</p>
</section>
