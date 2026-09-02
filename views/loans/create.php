<?php
/** @var array $products */
?>
<section class="page-head">
  <div>
    <h1>Solicitar crédito</h1>
    <p class="muted">Tu pedido se publica en el mercado para que otros usuarios lo fondeen.</p>
  </div>
</section>

<section class="panel narrow">
  <form method="post" action="<?= e(url('/loans/create')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Producto</label>
    <select name="product_id" required>
      <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>">
          <?= e($p['name']) ?> — <?= e(money($p['min_amount'])) ?> a <?= e(money($p['max_amount'])) ?> · <?= e(number_format((float)$p['annual_rate'],1)) ?>% TNA
        </option>
      <?php endforeach; ?>
    </select>
    <label>Monto</label>
    <input name="amount" required placeholder="50000">
    <label>Plazo (meses)</label>
    <input type="number" name="term_months" required min="1" max="36" value="12">
    <label>Motivo</label>
    <input name="purpose" maxlength="255" placeholder="Capital de trabajo, refacción, etc.">
    <p class="muted">Al publicar aceptás el <a href="<?= e(url('/legales/contrato-credito')) ?>" target="_blank">Contrato de crédito</a> y las tasas del producto (TNA/CFT). Usá el <a href="<?= e(url('/simulador')) ?>">simulador</a> antes de confirmar.</p>
    <label class="check"><input type="checkbox" name="accept_contract" value="1" required> Confirmo que revisé cuota, TNA y CFT estimado</label>
    <button class="btn btn-accent" type="submit">Publicar en el mercado</button>
  </form>
</section>
