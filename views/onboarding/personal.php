<?php
/** @var array $profile */
?>
<section class="page-head"><h1>Datos personales</h1><p class="muted">Paso 1 de 5</p></section>
<section class="panel narrow">
  <form method="post" action="<?= e(url('/onboarding/personal')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="form-row">
      <div><label>DNI</label><input name="dni" required value="<?= e($profile['dni'] ?? '') ?>"></div>
      <div><label>CUIT/CUIL</label><input name="cuit" value="<?= e($profile['cuit'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div><label>Fecha de nacimiento</label><input type="date" name="birth_date" value="<?= e($profile['birth_date'] ?? '') ?>"></div>
      <div><label>Género</label>
        <select name="gender">
          <option value="">—</option>
          <?php foreach (['F'=>'Femenino','M'=>'Masculino','X'=>'Otro/No binario'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($profile['gender'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <label>Teléfono celular</label>
    <input name="phone" required placeholder="11…" value="<?= e($profile['phone'] ?? '') ?>">
    <label>Calle y número</label>
    <input name="address_street" required value="<?= e($profile['address_street'] ?? '') ?>">
    <div class="form-row">
      <div><label>Ciudad</label><input name="address_city" required value="<?= e($profile['address_city'] ?? '') ?>"></div>
      <div><label>Provincia</label><input name="address_province" required value="<?= e($profile['address_province'] ?? '') ?>"></div>
    </div>
    <label>CP</label>
    <input name="address_zip" value="<?= e($profile['address_zip'] ?? '') ?>">
    <button class="btn btn-accent" type="submit">Guardar y continuar</button>
  </form>
</section>
