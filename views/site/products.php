<?php
/** @var array $products */
?>
<section class="section">
  <h1>Productos Credimax</h1>
  <div class="loan-grid">
    <?php foreach ($products as $p): ?>
      <article class="loan-card" style="cursor:default">
        <strong><?= e($p['name']) ?></strong>
        <div class="loan-amount"><?= e(money($p['min_amount'])) ?> – <?= e(money($p['max_amount'])) ?></div>
        <div class="muted"><?= (int)$p['min_term_months'] ?>–<?= (int)$p['max_term_months'] ?> meses · TNA <?= e(number_format((float)$p['annual_rate'],1)) ?>%</div>
        <div class="muted">Comisión originación <?= e(number_format((float)$p['origination_fee_pct'],2)) ?>%</div>
        <p><?= e($p['description'] ?? '') ?></p>
      </article>
    <?php endforeach; ?>
    <?php if (!$products): ?><p class="muted">Sin productos activos.</p><?php endif; ?>
  </div>
  <a class="btn btn-accent" href="<?= e(url('/simulador')) ?>">Simular</a>
</section>
