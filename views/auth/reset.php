<?php /** @var string $token */ ?>
<p class="muted">Elegí una contraseña nueva (mín. 8 caracteres, mayúscula, minúscula y número).</p>
<form method="post" action="<?= e(url('/reset-password')) ?>" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label>Nueva contraseña</label>
  <input type="password" name="password" required minlength="8" autocomplete="new-password">
  <button class="btn btn-accent btn-block" type="submit">Guardar contraseña</button>
</form>
<p class="auth-switch"><a href="<?= e(url('/login')) ?>">Volver al ingreso</a></p>
