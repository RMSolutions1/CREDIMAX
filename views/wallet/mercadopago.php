<?php
/** @var array $summary */
/** @var array $charges */
/** @var array $payments */
/** @var bool $enabled */
/** @var bool $sandbox */
$wallet = $summary['wallet'];
$sub = $summary['subaccount'];
?>
<section class="page-head">
  <div>
    <h1>Mi cuenta Mercado Pago</h1>
    <p class="muted">
      Tu billetera Credimax funciona como una sub-cuenta:
      <strong><?= e($sub['external_id'] ?? '—') ?></strong>.
      El dinero entra y sale por la cuenta operativa de Credimax en Mercado Pago.
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/wallet')) ?>">Volver a la billetera</a>
  </div>
</section>

<?php if (!$enabled): ?>
  <section class="panel">
    <h2>Mercado Pago todavía no está activo</h2>
    <p class="muted">
      Un administrador debe cargar las credenciales en
      <a href="<?= e(url('/admin/mercadopago')) ?>">Admin → Mercado Pago</a> para habilitar
      la carga de saldo y los cobros.
    </p>
  </section>
<?php else: ?>

<?php if ($sandbox): ?>
  <section class="panel">
    <h2>Modo de pruebas</h2>
    <p class="muted">La integración usa credenciales de prueba: los pagos no mueven dinero real.</p>
  </section>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span>Saldo disponible</span><strong><?= e(money($wallet['available_balance'])) ?></strong></div>
  <div class="stat"><span>Acreditado por Mercado Pago</span><strong><?= e(money($summary['collected_total'])) ?></strong></div>
  <div class="stat"><span>Pendiente de acreditar</span><strong><?= e(money($summary['pending_amount'])) ?></strong></div>
  <div class="stat"><span>Cuenta vinculada</span><strong><?= $summary['linked'] ? 'Sí' : 'No' ?></strong></div>
</div>

<div class="grid-2">
  <section class="panel">
    <h2>Cargar saldo</h2>
    <p class="muted">
      Pagás con tarjeta, dinero en cuenta o efectivo desde Mercado Pago.
      El saldo se acredita solo cuando Mercado Pago confirma el pago.
    </p>
    <form method="post" action="<?= e(url('/wallet/mp/cargar')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto a cargar</label>
      <input name="amount" required inputmode="decimal" placeholder="10000">
      <button class="btn btn-accent" type="submit">Ir a Mercado Pago</button>
    </form>
  </section>

  <section class="panel">
    <h2>Cobrar con link o QR</h2>
    <p class="muted">Generá un link de cobro; quien te paga no necesita tener cuenta en Credimax.</p>
    <form method="post" action="<?= e(url('/wallet/mp/cobro')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Concepto</label>
      <input name="title" required maxlength="120" placeholder="Cuota, servicio, venta...">
      <label>Monto</label>
      <input name="amount" required inputmode="decimal" placeholder="5000">
      <label>Nota (opcional)</label>
      <input name="note" maxlength="255">
      <button class="btn btn-accent" type="submit">Generar cobro</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Vincular tu cuenta de Mercado Pago</h2>
  <?php if ($summary['linked']): ?>
    <p class="muted">
      Vinculada desde <?= e((string) ($sub['linked_at'] ?? '—')) ?>
      · ID Mercado Pago <code><?= e((string) ($sub['mp_user_id'] ?? '—')) ?></code>
    </p>
    <form method="post" action="<?= e(url('/wallet/mp/desvincular')) ?>">
      <?= csrf_field() ?>
      <button class="btn" type="submit">Desvincular</button>
    </form>
  <?php else: ?>
    <p class="muted">
      Vincular tu cuenta agiliza los retiros y permite identificar tus pagos automáticamente.
      Credimax nunca ve tu contraseña: la autorización se hace en Mercado Pago.
    </p>
    <a class="btn btn-accent" href="<?= e(url('/wallet/mp/vincular')) ?>">Vincular mi cuenta</a>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Mis cobros</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Fecha</th><th>Código</th><th>Concepto</th><th>Monto</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($charges as $c): ?>
        <tr>
          <td><?= e($c['created_at']) ?></td>
          <td><code><?= e($c['code']) ?></code></td>
          <td><?= e($c['title']) ?></td>
          <td><?= e(money($c['amount'])) ?></td>
          <td><?= e(status_label((string) $c['status'])) ?></td>
          <td><a href="<?= e(url('/wallet/mp/cobro/' . $c['id'])) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$charges): ?><tr><td colspan="6" class="muted">Todavía no generaste cobros.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Movimientos con Mercado Pago</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Medio</th><th>Estado</th><th>Acreditado</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['created_at']) ?></td>
          <td><?= e($p['kind']) ?></td>
          <td><?= e(money($p['amount'])) ?></td>
          <td><?= e((string) ($p['payment_method_id'] ?? '—')) ?></td>
          <td><?= e((string) $p['status']) ?></td>
          <td><?= (int) $p['credited'] === 1 ? 'Sí' : 'No' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="6" class="muted">Sin movimientos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php endif; ?>
