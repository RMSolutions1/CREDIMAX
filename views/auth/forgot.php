<?php /** @var string $title */ ?>
<h1>Recuperar contraseña</h1>
<p class="muted">Ingresá tu email y te enviamos un enlace para restablecer la contraseña.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>" class="form">
  <?= csrf_field() ?>
  <label>Email</label>
  <input type="email" name="email" required autocomplete="username">
  <button class="btn btn-accent btn-block" type="submit">Enviar enlace</button>
</form>
<p class="auth-switch"><a href="<?= e(url('/login')) ?>">Volver al ingreso</a></p>
