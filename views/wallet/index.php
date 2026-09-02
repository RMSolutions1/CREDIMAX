<?php
/** @var array $wallet */
/** @var array $txs */
?>
<section class="page-head">
  <div>
    <h1>Billetera</h1>
    <p class="muted">Tu dinero en Credimax. Cargas, cobros, transferencias y retiros en un solo lugar.</p>
  </div>
</section>

<div class="money-card">
  <div class="money-card-top">
    <div>
      <span>Saldo disponible</span>
      <div class="money-amount"><?= e(money($wallet['available_balance'])) ?></div>
    </div>
    <span class="badge" style="background:rgba(232,201,122,.18);color:#E8C97A">ARS</span>
  </div>
  <p class="money-card-meta">
    Total <?= e(money($wallet['balance'])) ?>
    · Reservado <?= e(money($wallet['reserved_balance'])) ?>
    · Alias <strong><?= e($wallet['alias'] ?? '—') ?></strong>
  </p>
  <p class="money-card-meta" style="margin-top:.35rem">CVU <?= e($wallet['cvu'] ?? '—') ?></p>
</div>

<div class="quick-actions">
  <a href="<?= e(url('/wallet/mp')) ?>">Cargar<small>Mercado Pago</small></a>
  <a href="<?= e(url('/wallet/qr')) ?>">Cobrar<small>QR Credimax</small></a>
  <a href="<?= e(url('/banking/transfer')) ?>">Enviar<small>A CVU / ID</small></a>
  <a href="#retirar">Retirar<small>A tu banco</small></a>
</div>

<section class="panel">
  <h2>Sub-cuenta Mercado Pago</h2>
  <p class="muted">Tu saldo Credimax se espeja contra la cuenta madre de Mercado Pago. Las cargas y los cobros QR se acreditan solos cuando el pago está aprobado.</p>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/wallet/mp')) ?>">Abrir Mercado Pago</a>
    <a class="btn" href="<?= e(url('/wallet/mp')) ?>#cobrar">Generar link de cobro</a>
  </div>
</section>

<?php
$pendingScope = otp_pending_scope();
$isPendingWithdraw = is_string($pendingScope) && str_starts_with($pendingScope, 'wallet:withdraw:');
$pendingAmt = $isPendingWithdraw ? otp_pending_amount() : 0.0;
?>
<div class="grid-2">
  <section class="panel">
    <h2>Informar depósito manual</h2>
    <p class="muted">Si transferís por banco, informá el comprobante. Se acredita cuando tesorería confirma la recepción.</p>
    <form method="post" action="<?= e(url('/wallet/deposit')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto</label>
      <input name="amount" required placeholder="10000">
      <label>Referencia / comprobante</label>
      <input name="reference" placeholder="Nº de operación">
      <button class="btn" type="submit">Informar depósito</button>
    </form>
  </section>
  <section class="panel" id="retirar">
    <h2>Retirar a cuenta bancaria</h2>
    <?php if ($isPendingWithdraw && $pendingAmt > 0): ?>
      <div class="flash info" style="margin-bottom:1rem">
        Verificación completada. Revisá los datos de tu retiro de <strong><?= e(money($pendingAmt)) ?></strong> y confirmá la solicitud.
      </div>
    <?php else: ?>
      <p class="muted">Se debita tu disponible. Credimax ejecuta la transferencia a tu CBU o alias y tesorería la confirma.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/wallet/withdraw')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto</label>
      <input name="amount" required value="<?= e((string) old('amount', $isPendingWithdraw ? (string) $pendingAmt : '')) ?>">
      <label>CBU / CVU destino (22 dígitos)</label>
      <input name="cbu" maxlength="22" placeholder="0000000000000000000000" value="<?= e((string) old('cbu', otp_pending_extra('cbu', ''))) ?>">
      <label>Alias (si no hay CBU)</label>
      <input name="alias" placeholder="tu.alias.banco" value="<?= e((string) old('alias', otp_pending_extra('alias', ''))) ?>">
      <label>Titular</label>
      <input name="holder" placeholder="Nombre del titular" value="<?= e((string) old('holder', otp_pending_extra('holder', ''))) ?>">
      <button class="btn btn-accent" type="submit"><?= $isPendingWithdraw ? 'Confirmar retiro' : 'Solicitar retiro' ?></button>
    </form>
  </section>
</div>

<?php
$transferPending = is_string($pendingScope) && str_starts_with($pendingScope, 'wallet:transfer:');
$pendingTransferAmount = $transferPending ? otp_pending_amount() : 0.0;
?>
<div class="grid-2">
  <section class="panel">
    <h2>Transferir entre cuentas Credimax</h2>
    <?php if ($transferPending && $pendingTransferAmount > 0): ?>
      <div class="flash info" style="margin-bottom:1rem">2FA confirmado. Revisá la transferencia de <strong><?= e(money($pendingTransferAmount)) ?></strong> y confirmala para enviarla.</div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/wallet/transfer')) ?>" class="form">
      <?= csrf_field() ?><?= idem_field() ?>
      <label>Destino (Credimax ID o email)</label>
      <input name="target" required placeholder="CMX-XXXXXXXX" value="<?= e((string) old('target', otp_pending_extra('target', ''))) ?>">
      <label>Monto</label>
      <input name="amount" required value="<?= e((string) old('amount', $transferPending ? (string) $pendingTransferAmount : '')) ?>">
      <label>Nota</label>
      <input name="note" maxlength="255" value="<?= e((string) old('note', otp_pending_extra('note', ''))) ?>">
      <button class="btn btn-accent" type="submit"><?= $transferPending ? 'Confirmar transferencia' : 'Enviar' ?></button>
    </form>
  </section>
  <section class="panel">
    <h2>Datos de tu cuenta</h2>
    <ul class="kv">
      <li><span>Alias interno</span><strong><?= e($wallet['alias'] ?? '—') ?></strong></li>
      <li><span>CVU interno</span><strong><?= e($wallet['cvu'] ?? '—') ?></strong></li>
      <li><span>Código de cuenta</span><strong><?= e($wallet['account_code'] ?? '—') ?></strong></li>
    </ul>
    <p class="muted">El CVU/alias interno sirve para mover dinero dentro de Credimax. Los retiros al sistema nacional se concilian con tesorería.</p>
  </section>
</div>

<section class="panel">
  <h2>Movimientos</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Saldo</th><th>Ref</th><th>Detalle</th></tr>
      </thead>
      <tbody>
      <?php foreach ($txs as $t): ?>
        <tr>
          <td><?= e($t['created_at']) ?></td>
          <td><?= e($t['type']) ?></td>
          <td><?= e(money($t['amount'])) ?></td>
          <td><?= e(money($t['balance_after'])) ?></td>
          <td><code><?= e($t['reference']) ?></code></td>
          <td><?= e($t['description'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="6" class="muted">Sin movimientos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
