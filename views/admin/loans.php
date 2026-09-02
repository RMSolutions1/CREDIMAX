<?php
/** @var array $loans */
?>
<section class="page-head"><h1>Créditos</h1></section>
<section class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Código</th><th>Deudor</th><th>Monto</th><th>Fondeado</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($loans as $l): ?>
        <tr>
          <td><?= e($l['loan_code']) ?></td>
          <td><?= e($l['credimax_id']) ?></td>
          <td><?= e(money($l['principal'])) ?></td>
          <td><?= e(money($l['funded_amount'])) ?></td>
          <td><?= e(status_label($l['status'])) ?></td>
          <td><a href="<?= e(url('/loans/'.$l['id'])) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
