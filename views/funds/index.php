<?php
/** @var array $wallet */
/** @var array $mandate */
/** @var array $deposits */
/** @var float $exposure */
?>
<section class="page-head">
  <div>
    <h1>Mis fondos en Credimax</h1>
    <p class="muted">Depositá a la cuenta Credimax. El fondeo de créditos requiere tu aprobación manual en el mercado.</p>
  </div>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/wallet/mp')) ?>">Cargar con Mercado Pago</a>
    <a class="btn" href="<?= e(url('/wallet')) ?>">Ver billetera</a>
    <a class="btn" href="<?= e(url('/marketplace')) ?>">Mercado P2P</a>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Disponible</span><strong><?= e(money($wallet['available_balance'])) ?></strong></div>
  <div class="stat"><span>Reservado / invertido</span><strong><?= e(money($wallet['reserved_balance'] ?? 0)) ?></strong></div>
  <div class="stat"><span>Exposición activa</span><strong><?= e(money($exposure)) ?></strong></div>
  <div class="stat"><span>Alertas</span><strong><?= ($mandate['mode'] ?? 'manual') === 'auto' ? 'Activas' : 'Off' ?></strong></div>
</div>

<div class="grid-2">
  <section class="panel">
    <h2>Informar depósito a Credimax</h2>
    <p class="muted">Preferí <a href="<?= e(url('/wallet/mp')) ?>">Mercado Pago</a> para acreditación automática. Si transferís por banco, informá el monto y se acredita al confirmar la recepción.</p>
    <form method="post" action="<?= e(url('/funds/deposit')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Monto</label>
      <input name="amount" required placeholder="50000">
      <label>Método</label>
      <select name="method">
        <option value="transfer">Transferencia bancaria</option>
        <option value="cash">Efectivo / ventanilla</option>
        <option value="other">Otro</option>
      </select>
      <label>Referencia / comprobante</label>
      <input name="external_reference" placeholder="Nº de operación">
      <button class="btn btn-accent" type="submit">Informar depósito</button>
    </form>
  </section>

  <section class="panel">
    <h2>Perfil de alertas de inversión</h2>
    <p class="muted">
      Por normativa BCRA (PSCPP), <strong>vos aprobás cada crédito</strong>.
      El modo “alertas” solo te avisa oportunidades compatibles; no fondea solo.
    </p>
    <form method="post" action="<?= e(url('/funds/mandate')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Modo</label>
      <select name="mode">
        <option value="manual" <?= ($mandate['mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Solo mercado (sin alertas)</option>
        <option value="auto" <?= ($mandate['mode'] ?? '') === 'auto' ? 'selected' : '' ?>>Alertas según mi perfil</option>
      </select>
      <label>Máximo por crédito (referencia)</label>
      <input name="max_per_loan" value="<?= e((string)($mandate['max_per_loan'] ?? 50000)) ?>">
      <label>Exposición total máxima (referencia)</label>
      <input name="max_total_exposure" value="<?= e((string)($mandate['max_total_exposure'] ?? 500000)) ?>">
      <label>Tasa mínima anual (%)</label>
      <input name="min_annual_rate" value="<?= e((string)($mandate['min_annual_rate'] ?? 0)) ?>">
      <label>Bandas de riesgo</label>
      <input name="allowed_bands" value="<?= e((string)($mandate['allowed_bands'] ?? 'A,B,C')) ?>" placeholder="A,B,C">
      <button class="btn btn-accent" type="submit">Guardar perfil</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Historial de depósitos</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Ref</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($deposits as $d): ?>
        <tr>
          <td><?= e($d['created_at']) ?></td>
          <td><?= e(money($d['amount'])) ?></td>
          <td><?= e($d['method']) ?></td>
          <td><?= e($d['external_reference'] ?? '—') ?></td>
          <td><?= e(status_label($d['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$deposits): ?><tr><td colspan="5" class="muted">Todavía no informaste depósitos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
