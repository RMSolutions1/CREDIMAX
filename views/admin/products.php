<?php
/** @var array $products */
?>
<section class="page-head"><h1>Productos de crédito</h1></section>
<section class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Nombre</th><th>Min</th><th>Max</th><th>Tasa</th><th>Activo</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td><?= e(money($p['min_amount'])) ?></td>
          <td><?= e(money($p['max_amount'])) ?></td>
          <td><?= e(number_format((float)$p['annual_rate'],2)) ?>%</td>
          <td><?= (int)$p['is_active'] ? 'Sí' : 'No' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel narrow">
  <h2>Nuevo / editar producto</h2>
  <form method="post" action="<?= e(url('/admin/products')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="0">
    <label>Nombre</label>
    <input name="name" required>
    <div class="form-row">
      <div><label>Mínimo</label><input name="min_amount" required></div>
      <div><label>Máximo</label><input name="max_amount" required></div>
    </div>
    <div class="form-row">
      <div><label>Plazo mín</label><input type="number" name="min_term_months" value="3"></div>
      <div><label>Plazo máx</label><input type="number" name="max_term_months" value="24"></div>
    </div>
    <div class="form-row">
      <div><label>TNA %</label><input name="annual_rate" required></div>
      <div><label>Comisión %</label><input name="origination_fee_pct" value="2.5"></div>
    </div>
    <label class="check"><input type="checkbox" name="is_active" checked> Activo</label>
    <button class="btn btn-accent" type="submit">Guardar producto</button>
  </form>
</section>
