<h1><i class="fa-solid fa-arrow-right-to-bracket emerald"></i> Ingresar a tu cuenta</h1>
<p class="muted"><i class="fa-solid fa-envelope-open-text"></i> Email y contraseña de tu cuenta Credimax.</p>
<?php if (\App\Core\App::config('app_env') === 'local'): ?>
  <div class="demo-hint" role="note">
    <strong><i class="fa-solid fa-flask"></i> Cuentas de prueba (local)</strong>
    <ul>
      <li>Admin · <code>admin@credimax.test</code></li>
      <li>Inversor · <code>inversor@credimax.test</code></li>
      <li>Solicitante · <code>solicitante@credimax.test</code></li>
    </ul>
    <p>Contraseña: <code>Demo1234!</code></p>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('/login')) ?>" class="form">
  <?= csrf_field() ?>
  <label><i class="fa-solid fa-at"></i> Email</label>
  <input type="email" name="email" required value="<?= e((string) old('email')) ?>" autocomplete="username">
  <label><i class="fa-solid fa-lock"></i> Contraseña</label>
  <input type="password" name="password" required autocomplete="current-password">
  <button class="btn btn-accent btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Ingresar</button>
</form>
<p class="auth-switch"><a href="<?= e(url('/forgot-password')) ?>"><i class="fa-solid fa-key"></i> ¿Olvidaste tu contraseña?</a></p>
<p class="auth-switch">¿No tenés cuenta? <a href="<?= e(url('/register')) ?>"><i class="fa-solid fa-user-plus"></i> Abrí una en minutos</a></p>
