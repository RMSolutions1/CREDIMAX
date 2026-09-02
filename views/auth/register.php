<h1><i class="fa-solid fa-user-plus emerald"></i> Abrí tu cuenta</h1>
<p class="muted"><i class="fa-solid fa-shield-halved"></i> Identidad Credimax, billetera en pesos y acceso al mercado P2P. Después completás KYC.</p>
<form method="post" action="<?= e(url('/register')) ?>" class="form">
  <?= csrf_field() ?>
  <label><i class="fa-solid fa-users"></i> Tipo de cuenta</label>
  <select name="account_type">
    <option value="persona" <?= old('account_type', 'persona') === 'persona' ? 'selected' : '' ?>>Persona humana</option>
    <option value="pyme" <?= old('account_type') === 'pyme' ? 'selected' : '' ?>>PyME / Persona jurídica</option>
  </select>
  <div class="form-row">
    <div>
      <label><i class="fa-solid fa-id-card"></i> Nombre / Razón social (corto)</label>
      <input name="first_name" required value="<?= e((string) old('first_name')) ?>">
    </div>
    <div>
      <label><i class="fa-solid fa-signature"></i> Apellido / Nombre comercial</label>
      <input name="last_name" required value="<?= e((string) old('last_name')) ?>">
    </div>
  </div>
  <label><i class="fa-solid fa-at"></i> Email</label>
  <input type="email" name="email" required value="<?= e((string) old('email')) ?>">
  <div class="form-row">
    <div>
      <label><i class="fa-solid fa-passport"></i> DNI / CUIT</label>
      <input name="dni" required value="<?= e((string) old('dni')) ?>">
    </div>
    <div>
      <label><i class="fa-solid fa-mobile-screen"></i> Teléfono</label>
      <input name="phone" required value="<?= e((string) old('phone')) ?>">
    </div>
  </div>
  <label><i class="fa-solid fa-lock"></i> Contraseña (mín. 8, mayúscula, minúscula y número)</label>
  <input type="password" name="password" required minlength="8" autocomplete="new-password">
  <label class="check"><input type="checkbox" name="resident_ar" value="1" required> <i class="fa-solid fa-flag gold"></i> Declaro residencia en Argentina y operatoria en pesos (ARS)</label>
  <label class="check"><input type="checkbox" name="accept_terms" value="1" required> Acepto <a href="<?= e(url('/legales/terminos')) ?>" target="_blank"><i class="fa-solid fa-file-contract"></i> Términos</a></label>
  <label class="check"><input type="checkbox" name="accept_privacy" value="1" required> Acepto <a href="<?= e(url('/legales/privacidad')) ?>" target="_blank"><i class="fa-solid fa-user-shield"></i> Privacidad</a></label>
  <label class="check"><input type="checkbox" name="accept_adhesion" value="1" required> Acepto el <a href="<?= e(url('/legales/adhesion')) ?>" target="_blank"><i class="fa-solid fa-handshake"></i> Contrato de adhesión</a> y el esquema de <a href="<?= e(url('/legales/fideicomiso')) ?>" target="_blank"><i class="fa-solid fa-scale-unbalanced-flip"></i> fideicomiso / segregación</a></label>
  <label class="check"><input type="checkbox" name="accept_risk" value="1" required> Acepto el <a href="<?= e(url('/legales/cumplimiento')) ?>" target="_blank"><i class="fa-solid fa-gavel"></i> marco regulatorio</a>: Credimax no es entidad financiera del BCRA y no garantiza el cobro</label>
  <button class="btn btn-accent btn-block" type="submit"><i class="fa-solid fa-rocket"></i> Crear mi cuenta</button>
</form>
<p class="auth-switch">¿Ya tenés cuenta? <a href="<?= e(url('/login')) ?>"><i class="fa-solid fa-arrow-right-to-bracket"></i> Ingresar</a></p>
