<?php
/** @var array $incoming */
/** @var array $outgoing */
/** @var array $wallet */
?>
<section class="page-head"><h1>DEBIN</h1><p class="muted">Débito inmediato con aprobación del pagador · red privada</p></section>

<div class="grid-2">
  <section class="panel">
    <h2>Crear DEBIN</h2>
    <form method="post" action="<?= e(url('/banking/debin')) ?>" class="form">
      <?= csrf_field() ?>
      <label>CVU/Alias del pagador</label>
      <input name="destination" required>
      <label>Monto</label>
      <input name="amount" required>
      <label>Expiración (minutos, máx 4320)</label>
      <input type="number" name="expiration" value="60" min="1" max="4320">
      <label>Descripción</label>
      <input name="description">
      <button class="btn btn-accent" type="submit">Solicitar cobro</button>
    </form>
  </section>
  <section class="panel">
    <h2>Pendientes de tu aprobación</h2>
    <?php foreach ($incoming as $d): ?>
      <div class="list-item">
        <div>
          <strong><?= e($d['debin_id']) ?></strong>
          <div class="muted"><?= e(money($d['amount'])) ?> · <?= e($d['status']) ?> · vence <?= e($d['expires_at']) ?></div>
        </div>
        <?php if ($d['status'] === 'AWAITING_CONFIRMATION'): ?>
        <form method="post" action="<?= e(url('/banking/debin/' . $d['debin_id'])) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button class="btn btn-accent" name="decision" value="approve" type="submit">Aprobar</button>
          <button class="btn" name="decision" value="reject" type="submit">Rechazar</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$incoming): ?><p class="muted">Sin DEBIN entrantes.</p><?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>DEBIN emitidos por vos</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Monto</th><th>Estado</th><th>Expira</th></tr></thead>
      <tbody>
      <?php foreach ($outgoing as $d): ?>
        <tr>
          <td><?= e($d['debin_id']) ?></td>
          <td><?= e(money($d['amount'])) ?></td>
          <td><?= e($d['status']) ?></td>
          <td><?= e($d['expires_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
