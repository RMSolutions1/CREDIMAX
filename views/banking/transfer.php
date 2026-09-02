<?php
/** @var array $wallet */
/** @var array $beneficiaries */
?>
<section class="page-head">
  <div>
    <h1>Transferir</h1>
    <p class="muted">Consultá titularidad antes de pagar. Transferencias en la red privada Credimax.</p>
  </div>
</section>

<?php $lookup = \App\Core\Session::get('_lookup'); \App\Core\Session::forget('_lookup'); ?>

<div class="grid-2">
  <section class="panel">
    <h2>1. Verificar destinatario</h2>
    <form method="post" action="<?= e(url('/banking/lookup')) ?>" class="form">
      <?= csrf_field() ?>
      <label>CVU (22 dígitos) o Alias</label>
      <input name="q" required placeholder="900... o credimax.xxx">
      <button class="btn" type="submit">Consultar titular</button>
    </form>
    <?php if ($lookup): ?>
      <div class="banner" style="margin-top:1rem">
        <strong><?= e($lookup['owners'][0]['display_name'] ?? '') ?></strong><br>
        ID <?= e($lookup['owners'][0]['id'] ?? '') ?> · CVU <?= e($lookup['account_routing']['address'] ?? '') ?> · Alias <?= e($lookup['label'] ?? '') ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>2. Enviar dinero</h2>
    <form method="post" action="<?= e(url('/banking/transfer')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Destino (CVU o Alias)</label>
      <input name="destination" required value="<?= e($lookup['account_routing']['address'] ?? $lookup['label'] ?? '') ?>">
      <label>Monto</label>
      <input name="amount" required>
      <label>Descripción</label>
      <input name="description" maxlength="100">
      <label class="check"><input type="checkbox" name="save_beneficiary" value="1"> Guardar beneficiario</label>
      <button class="btn btn-accent" type="submit">Transferir ahora</button>
    </form>
  </section>
</div>

<?php if (!empty($beneficiaries)): ?>
<section class="panel">
  <h2>Beneficiarios</h2>
  <div class="list">
    <?php foreach ($beneficiaries as $b): ?>
      <div class="list-item">
        <div>
          <strong><?= e($b['label']) ?></strong>
          <div class="muted"><?= e($b['cvu_cbu'] ?: $b['alias']) ?> · <?= e($b['owner_name'] ?? '') ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
