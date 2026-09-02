<?php
/** @var array $treasury */
/** @var array $pending */
/** @var array $ledger */
/** @var array $mandates */
?>
<section class="page-head">
  <div>
    <h1>Fondos y tesorería</h1>
    <p class="muted">Administrá capital propio, AUM de terceros y depósitos de prestamistas.</p>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Capital propio</span><strong><?= e(money($treasury['own_balance'])) ?></strong></div>
  <div class="stat"><span>AUM terceros</span><strong><?= e(money($treasury['third_party_aum'])) ?></strong></div>
  <div class="stat"><span>Depósitos pendientes</span><strong><?= e(money($treasury['pending_deposits'])) ?></strong></div>
  <div class="stat"><span>Billeteras clientes</span><strong><?= e(money($treasury['customer_wallets_total'])) ?></strong></div>
  <div class="stat"><span>Total bajo gestión</span><strong><?= e(money($treasury['total_under_management'])) ?></strong></div>
</div>

<?php
$pendingScope = otp_pending_scope();
$adjustPending = is_string($pendingScope) && str_starts_with($pendingScope, 'admin:wallet-adjust:');
$injectPending = is_string($pendingScope) && str_starts_with($pendingScope, 'admin:inject-own:');
?>
<div class="grid-2">
  <section class="panel">
    <h2>Ajustar saldo (+/−)</h2>
    <?php if ($adjustPending): ?>
      <div class="flash info" style="margin-bottom:1rem">2FA confirmado. Revisá el ajuste de <strong><?= e(money(otp_pending_amount())) ?></strong> y confirmalo para aplicarlo.</div>
    <?php else: ?>
      <p class="muted">Como fintech / neobank: acreditar o debitar billeteras.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/wallet-adjust')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Credimax ID o email</label>
      <input name="credimax_id" required placeholder="CMX-… o email" value="<?= e((string) old('credimax_id', otp_pending_extra('credimax_id', ''))) ?>">
      <label>Monto</label>
      <input name="amount" required value="<?= e((string) old('amount', $adjustPending ? (string) otp_pending_amount() : '')) ?>">
      <label>Dirección</label>
      <select name="direction">
        <option value="credit" <?= old('direction', otp_pending_extra('direction', 'credit')) === 'credit' ? 'selected' : '' ?>>Acreditar (+)</option>
        <option value="debit" <?= old('direction', otp_pending_extra('direction', '')) === 'debit' ? 'selected' : '' ?>>Debitar (−)</option>
      </select>
      <label>Tipo de fondo</label>
      <select name="fund_type">
        <option value="customer" <?= old('fund_type', otp_pending_extra('fund_type', 'customer')) === 'customer' ? 'selected' : '' ?>>Cliente / AUM</option>
        <option value="own" <?= old('fund_type', otp_pending_extra('fund_type', '')) === 'own' ? 'selected' : '' ?>>Capital propio</option>
        <option value="aum_adjust" <?= old('fund_type', otp_pending_extra('fund_type', '')) === 'aum_adjust' ? 'selected' : '' ?>>Solo ajuste contable AUM</option>
      </select>
      <label>Motivo</label>
      <input name="reason" required placeholder="Ej. depósito bancario recibido" value="<?= e((string) old('reason', otp_pending_extra('reason', ''))) ?>">
      <button class="btn btn-accent" type="submit"><?= $adjustPending ? 'Confirmar ajuste' : 'Aplicar ajuste' ?></button>
    </form>
  </section>

  <section class="panel">
    <h2>Inyectar capital propio</h2>
    <?php if ($injectPending): ?>
      <div class="flash info" style="margin-bottom:1rem">2FA confirmado. Listo para inyectar <strong><?= e(money(otp_pending_amount())) ?></strong> de capital propio.</div>
    <?php else: ?>
      <p class="muted">Registra capital propio contable de Credimax (no se usa para prestar en modalidad PSCPP).</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/funds/inject-own')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto</label>
      <input name="amount" required value="<?= e((string) old('amount', $injectPending ? (string) otp_pending_amount() : '')) ?>">
      <label>Motivo</label>
      <input name="reason" value="<?= e((string) old('reason', otp_pending_extra('reason', 'Aporte capital Credimax'))) ?>">
      <button class="btn btn-accent" type="submit"><?= $injectPending ? 'Confirmar inyección' : 'Inyectar' ?></button>
    </form>

    <hr>
    <h2>Recalcular AUM de terceros</h2>
    <p class="muted">Reajusta el AUM contable a la suma real de las billeteras de clientes. Usalo si el panel muestra un respaldo distinto al saldo en circulación.</p>
    <form method="post" action="<?= e(url('/admin/funds/recalcular-aum')) ?>" class="form">
      <?= csrf_field() ?>
      <button class="btn" type="submit">Recalcular AUM</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Depósitos pendientes de confirmación</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>ID</th><th>Usuario</th><th>Monto</th><th>Método</th><th>Ref</th><th>Fecha</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($pending as $d): ?>
        <tr>
          <td>#<?= (int)$d['id'] ?></td>
          <td><?= e($d['credimax_id']) ?><br><span class="muted"><?= e($d['email']) ?></span></td>
          <td><?= e(money($d['amount'])) ?></td>
          <td><?= e($d['method']) ?></td>
          <td><?= e($d['external_reference'] ?? '—') ?></td>
          <td><?= e($d['created_at']) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/funds/deposit/' . $d['id'])) ?>" class="inline-forms">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="confirm">
              <input name="notes" placeholder="Notas" style="max-width:140px">
              <button class="btn btn-sm btn-accent" type="submit">Confirmar</button>
            </form>
            <form method="post" action="<?= e(url('/admin/funds/deposit/' . $d['id'])) ?>" class="inline-forms">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <button class="btn btn-sm" type="submit">Rechazar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pending): ?><tr><td colspan="7" class="muted">Sin depósitos pendientes.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Retiros pendientes de pago bancario</h2>
  <p class="muted">El saldo ya fue debitado de la billetera. Al marcar “Pagado” confirmás que ejecutaste la transferencia a CBU/alias.</p>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>ID</th><th>Usuario</th><th>Monto</th><th>Destino</th><th>Titular</th><th>Fecha</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach (($pendingWithdrawals ?? []) as $w): ?>
        <tr>
          <td>#<?= (int)$w['id'] ?></td>
          <td><?= e($w['credimax_id']) ?><br><span class="muted"><?= e($w['email']) ?></span></td>
          <td><?= e(money($w['amount'])) ?></td>
          <td>
            <?php if (!empty($w['destination_cbu'])): ?>CBU <?= e($w['destination_cbu']) ?><br><?php endif; ?>
            <?php if (!empty($w['destination_alias'])): ?>Alias <?= e($w['destination_alias']) ?><?php endif; ?>
            <?php if (empty($w['destination_cbu']) && empty($w['destination_alias'])): ?>—<?php endif; ?>
          </td>
          <td><?= e($w['destination_holder'] ?? '—') ?></td>
          <td><?= e($w['created_at']) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/funds/withdraw/' . $w['id'])) ?>" class="inline-forms">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="paid">
              <input name="notes" placeholder="Nº transferencia" style="max-width:140px">
              <button class="btn btn-sm btn-accent" type="submit">Pagado</button>
            </form>
            <form method="post" action="<?= e(url('/admin/funds/withdraw/' . $w['id'])) ?>" class="inline-forms">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <button class="btn btn-sm" type="submit">Rechazar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($pendingWithdrawals)): ?><tr><td colspan="7" class="muted">Sin retiros pendientes.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="grid-2">
  <section class="panel">
    <h2>Mandatos auto-inversión activos</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Usuario</th><th>Máx/crédito</th><th>Exposición máx</th><th>Tasa mín</th><th>Bandas</th><th>Disponible</th></tr></thead>
        <tbody>
        <?php foreach ($mandates as $m): ?>
          <tr>
            <td><?= e($m['credimax_id']) ?></td>
            <td><?= e(money($m['max_per_loan'])) ?></td>
            <td><?= e(money($m['max_total_exposure'])) ?></td>
            <td><?= e(number_format((float)$m['min_annual_rate'], 1)) ?>%</td>
            <td><?= e($m['allowed_bands']) ?></td>
            <td><?= e(money($m['available_balance'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$mandates): ?><tr><td colspan="6" class="muted">Nadie autorizó auto-inversión aún.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <h2>Últimos movimientos admin</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Dir</th><th>Monto</th><th>Tipo</th><th>Motivo</th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $l): ?>
          <tr>
            <td><?= e($l['created_at']) ?></td>
            <td><?= e($l['credimax_id']) ?></td>
            <td><?= e($l['direction']) ?></td>
            <td><?= e(money($l['amount'])) ?></td>
            <td><?= e($l['fund_type']) ?></td>
            <td><?= e($l['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ledger): ?><tr><td colspan="6" class="muted">Sin movimientos.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
