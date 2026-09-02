<?php
/** @var array $profile */
?>
<section class="page-head"><h1>Situación laboral</h1><p class="muted">Paso 3 de 5</p></section>
<section class="panel narrow">
  <form method="post" action="<?= e(url('/onboarding/laboral')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Situación</label>
    <select name="employment_status" required>
      <?php foreach (['relacion_dependencia'=>'Relación de dependencia','monotributo'=>'Monotributo','autonomo'=>'Autónomo','jubilado'=>'Jubilado/Pensionado','otro'=>'Otro'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= ($profile['employment_status']??'')===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <label>Empleador / actividad</label>
    <input name="employer_name" value="<?= e($profile['employer_name'] ?? '') ?>">
    <label>Antigüedad (meses)</label>
    <input type="number" name="job_seniority_months" min="0" value="<?= (int)($profile['job_seniority_months'] ?? 0) ?>">
    <label>Ingreso mensual neto</label>
    <input name="monthly_income" required value="<?= e((string)($profile['monthly_income'] ?? '')) ?>">
    <label>Tipo de ingreso</label>
    <select name="income_type">
      <option value="sueldo">Sueldo</option>
      <option value="facturacion">Facturación</option>
      <option value="jubilacion">Jubilación</option>
      <option value="otro">Otro</option>
    </select>
    <button class="btn btn-accent" type="submit">Continuar</button>
  </form>
</section>
