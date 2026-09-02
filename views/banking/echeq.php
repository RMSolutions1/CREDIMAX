<?php
/** @var array $received */
/** @var array $issued */
/** @var array $wallet */
?>
<section class="page-head"><h1>ECHEQ</h1><p class="muted">Cheques electrónicos privados Credimax</p></section>

<div class="grid-2">
  <section class="panel">
    <h2>Emitir ECHEQ</h2>
    <form method="post" action="<?= e(url('/banking/echeq')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto</label>
      <input name="amount" required>
      <label>Beneficiario (CVU/Alias opcional)</label>
      <input name="destination">
      <label>Nombre beneficiario</label>
      <input name="receiver_name" required>
      <label>CUIT/DNI beneficiario</label>
      <input name="receiver_cuit">
      <label>Fecha de pago</label>
      <input type="date" name="payment_date" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>">
      <label>Tipo</label>
      <select name="check_type"><option value="CPD">Pago diferido (CPD)</option><option value="CC">Común (CC)</option></select>
      <label>Descripción</label>
      <input name="description">
      <button class="btn btn-accent" type="submit">Emitir</button>
    </form>
  </section>
  <section class="panel">
    <h2>Recibidos (ACTIVE)</h2>
    <?php foreach ($received as $e): ?>
      <div class="list-item">
        <div>
          <strong><?= e($e['id']) ?></strong>
          <div class="muted"><?= e(money($e['details']['check']['amount'] ?? 0)) ?> · <?= e($e['details']['check']['issued_to']['name'] ?? '') ?></div>
        </div>
        <form method="post" action="<?= e(url('/banking/echeq/' . $e['id'])) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button class="btn btn-accent" name="action" value="DEPOSIT" type="submit">Depositar</button>
          <button class="btn" name="action" value="REJECT" type="submit">Rechazar</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$received): ?><p class="muted">Sin ECHEQ recibidos.</p><?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>Emitidos por vos</h2>
  <?php foreach ($issued as $e): ?>
    <div class="list-item">
      <div>
        <strong><?= e($e['id']) ?></strong>
        <div class="muted"><?= e(money($e['details']['check']['amount'] ?? 0)) ?> · <?= e($e['status']) ?> · paga <?= e($e['details']['check']['payment_date'] ?? '') ?></div>
      </div>
      <form method="post" action="<?= e(url('/banking/echeq/' . $e['id'])) ?>">
        <?= csrf_field() ?>
        <button class="btn" name="action" value="CANCEL" type="submit">Cancelar</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$issued): ?><p class="muted">Sin emisiones activas.</p><?php endif; ?>
</section>
