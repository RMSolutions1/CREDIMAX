<?php
/** @var array $loan */
/** @var array $installments */
/** @var array $fundings */
/** @var float $remaining */
?>
<section class="page-head">
  <div>
    <h1><?= e($loan['loan_code']) ?></h1>
    <p class="muted"><?= e($loan['product_name']) ?> · <?= e(status_label($loan['status'])) ?> · Solicitante <?= e($loan['credimax_id']) ?></p>
  </div>
</section>

<div class="stat-grid">
  <div class="stat"><span>Principal</span><strong><?= e(money($loan['principal'])) ?></strong></div>
  <div class="stat"><span>Fondeado</span><strong><?= e(money($loan['funded_amount'])) ?></strong></div>
  <div class="stat"><span>Cuota</span><strong><?= e(money($loan['installment_amount'])) ?></strong></div>
  <div class="stat"><span>Total a pagar</span><strong><?= e(money($loan['total_payable'])) ?></strong></div>
</div>

<div class="grid-2">
  <section class="panel">
    <h2>Detalle</h2>
    <ul class="kv">
      <li><span>Tasa</span><strong><?= e(number_format((float)$loan['annual_rate'], 2)) ?>% TNA fija</strong></li>
      <li><span>Perfil riesgo</span><strong><?= e((string)($loan['risk_band'] ?? '—')) ?> · <?= e(\App\Services\ScoringService::bandLabel((string)($loan['risk_band'] ?? ''))) ?></strong></li>
      <li><span>TEA</span><strong><?= e(number_format((float)($loan['tea'] ?? ((pow(1 + ((float)$loan['annual_rate'] / 100) / 12, 12) - 1) * 100)), 2)) ?>%</strong></li>
      <?php if (isset($loan['cft_tna']) && $loan['cft_tna'] !== null): ?>
      <li><span>CFT TNA</span><strong><?= e(number_format((float)$loan['cft_tna'], 2)) ?>%</strong></li>
      <?php endif; ?>
      <?php if (isset($loan['cft_tea']) && $loan['cft_tea'] !== null): ?>
      <li class="rate-highlight"><span>CFT TEA</span><strong><?= e(number_format((float)$loan['cft_tea'], 2)) ?>%</strong></li>
      <?php endif; ?>
      <?php if (!empty($loan['origination_fee_amount'])): ?>
      <li><span>Comisión originación</span><strong><?= e(money($loan['origination_fee_amount'])) ?></strong></li>
      <?php endif; ?>
      <?php
        $remain = round((float)$loan['principal'] - (float)$loan['funded_amount'], 2);
        if ($remain > 0 && in_array($loan['status'], ['open','funding'], true)):
          try {
            $inv = invest_quote(min(10000, $remain), (float)$loan['annual_rate'], (int)$loan['term_months'], (string)($loan['risk_band'] ?? 'C'));
      ?>
      <li><span>Retorno est. / $10.000</span><strong><?= e(money($inv['expected_interest'])) ?></strong></li>
      <?php } catch (\Throwable $e) {} endif; ?>
      <li><span>Plazo</span><strong><?= (int)$loan['term_months'] ?> meses</strong></li>
      <li><span>Restante fondeo</span><strong><?= e(money($remaining)) ?></strong></li>
      <li><span>Próximo vencimiento</span><strong><?= e($loan['next_due_date'] ?? '—') ?></strong></li>
      <li><span>Motivo</span><strong><?= e($loan['purpose'] ?: '—') ?></strong></li>
    </ul>

    <?php if (in_array($loan['status'], ['open','funding'], true) && (int)$loan['borrower_id'] !== auth_id()): ?>
      <form method="post" action="<?= e(url('/loans/' . $loan['id'] . '/fund')) ?>" class="form">
        <?= csrf_field() ?>
        <label>Monto a fondear (máx. <?= e(money($remaining)) ?>)</label>
        <input name="amount" required>
        <button class="btn btn-accent" type="submit">Otorgar crédito / Invertir</button>
      </form>
    <?php endif; ?>

    <?php if (is_admin() && in_array($loan['status'], ['open','funding'], true)): ?>
      <form method="post" action="<?= e(url('/admin/loans/' . $loan['id'] . '/auto-invest')) ?>" class="form" style="margin-top:12px">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Enviar alertas a inversores (sin fondeo auto)</button>
      </form>
    <?php endif; ?>

    <?php if ($loan['status'] === 'active' && (int)$loan['borrower_id'] === auth_id()): ?>
      <form method="post" action="<?= e(url('/loans/' . $loan['id'] . '/pay')) ?>" class="form">
        <?= csrf_field() ?>
        <button class="btn btn-accent" type="submit">Pagar próxima cuota</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Inversores</h2>
    <?php if (!$fundings): ?>
      <p class="muted">Aún no hay fondeos.</p>
    <?php else: ?>
      <div class="list">
        <?php foreach ($fundings as $f): ?>
          <div class="list-item">
            <div>
              <strong><?= e($f['credimax_id']) ?></strong>
              <div class="muted"><?= e(status_label($f['status'])) ?><?php if (!empty($f['funding_source'])): ?> · <?= e($f['funding_source']) ?><?php endif; ?></div>
            </div>
            <strong><?= e(money($f['amount'])) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>Cuotas</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Vence</th><th>Capital</th><th>Interés</th><th>Total</th><th>Pagado</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($installments as $i): ?>
        <tr>
          <td><?= (int)$i['installment_number'] ?></td>
          <td><?= e($i['due_date']) ?></td>
          <td><?= e(money($i['principal_portion'])) ?></td>
          <td><?= e(money($i['interest_portion'])) ?></td>
          <td><?= e(money($i['total_amount'])) ?></td>
          <td><?= e(money($i['paid_amount'])) ?></td>
          <td><?= e(status_label($i['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
