<?php
/** @var array $loans */
?>
<section class="page-head"><h1>Mis créditos</h1></section>
<section class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Código</th><th>Producto</th><th>Monto</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($loans as $l): ?>
        <tr>
          <td><?= e($l['loan_code']) ?></td>
          <td><?= e($l['product_name']) ?></td>
          <td><?= e(money($l['principal'])) ?></td>
          <td><?= e(status_label($l['status'])) ?></td>
          <td><a href="<?= e(url('/loans/' . $l['id'])) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$loans): ?><tr><td colspan="5" class="muted">Sin créditos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
