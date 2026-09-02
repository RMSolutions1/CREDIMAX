<?php
/** @var array $rows */
/** @var array $summary */
/** @var array $proximos30 */
$invested = (float) ($summary['invested'] ?? 0);
$earned = (float) ($summary['earned'] ?? 0);
$pending = (float) ($summary['pending'] ?? 0);
$avgTna = (float) ($summary['avg_tna'] ?? 0);
$roi = (float) ($summary['roi_anual'] ?? 0);
$bandDist = (array) ($summary['band_dist'] ?? []);
$operCount = (int) ($summary['oper_count'] ?? 0);
arsort($bandDist);
?>
<section class="page-head">
  <div>
    <h1>Mis inversiones</h1>
    <p class="muted">Cartera de créditos fondeados. Panel CEO-grade: rentabilidad, riesgo y flujo de fondos en tiempo real.</p>
  </div>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap">
    <a class="btn" href="<?= e(url('/marketplace')) ?>"><i class="fa-solid fa-store" style="margin-right:.35rem"></i>Volver al mercado</a>
    <a class="btn btn-accent" href="<?= e(url('/investments/afip-rentas.csv?year=' . (int)date('Y'))) ?>"><i class="fa-solid fa-file-csv" style="margin-right:.35rem"></i>Exportar AFIP <?= (int)date('Y') ?></a>
  </div>
</section>

<section class="stats-grid">
  <div class="stat-card" style="border-top:4px solid #2563eb">
    <div class="muted" style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">Capital fondeado</div>
    <div class="stat-value"><?= e(money($invested)) ?></div>
    <div class="muted" style="margin-top:.4rem"><?= $operCount ?> operación/es · Cartera activa</div>
  </div>
  <div class="stat-card" style="border-top:4px solid #16a34a">
    <div class="muted" style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">Intereses cobrados</div>
    <div class="stat-value" style="color:#15803d"><?= e(money($earned)) ?></div>
    <div class="muted" style="margin-top:.4rem">Flujo realizado a la fecha</div>
  </div>
  <div class="stat-card" style="border-top:4px solid #d97706">
    <div class="muted" style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">Pendiente de cobro</div>
    <div class="stat-value" style="color:#92400e"><?= e(money($pending)) ?></div>
    <div class="muted" style="margin-top:.4rem">Capital + intereses por vencer</div>
  </div>
  <div class="stat-card" style="border-top:4px solid #7c3aed">
    <div class="muted" style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">TNA promedio · ROI</div>
    <div class="stat-value" style="color:#6d28d9"><?= e(number_format($avgTna,2)) ?>% TNA</div>
    <div class="muted" style="margin-top:.4rem">ROI anualizado estimado <?= e(number_format($roi,2)) ?>%</div>
  </div>
</section>

<div style="display:grid;grid-template-columns: 1.1fr .9fr;gap:1.25rem;margin-top:1.25rem">
  <section class="panel">
    <h2 style="margin:0 0 1rem">Próximos cobros (30 días)</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vence</th><th>Crédito</th><th>Cuota</th><th>Deudor</th><th>Importe</th></tr></thead>
        <tbody>
        <?php foreach ($proximos30 as $c): ?>
          <tr>
            <td><?= e(date('d/m/Y', strtotime($c['due_date']))) ?></td>
            <td><strong><?= e($c['loan_code']) ?></strong></td>
            <td>#<?= (int)$c['installment_number'] ?></td>
            <td><?= e($c['Nombre'] . ' ' . $c['Apellido']) ?></td>
            <td style="font-weight:700;text-align:right"><?= e(money($c['amount_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$proximos30): ?><tr><td colspan="5" class="muted">Sin cobros programados en los próximos 30 días.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <h2 style="margin:0 0 1rem">Distribución por banda de riesgo</h2>
    <?php if (!$bandDist): ?>
      <p class="muted">Todavía no hay asignación por banda.</p>
    <?php else: ?>
    <div>
      <?php
        $max = max(array_values($bandDist));
        $pal = ['A'=>'#16a34a','B'=>'#0284c7','C'=>'#ca8a04','D'=>'#ea580c','E'=>'#dc2626','F'=>'#9f1239','NR'=>'#6b7280'];
      ?>
      <?php foreach ($bandDist as $b => $amt): ?>
        <?php $pct = $invested > 0 ? round(($amt / $invested) * 100, 1) : 0; ?>
        <div style="margin-bottom:.75rem">
          <div style="display:flex;justify-content:space-between;margin-bottom:.25rem">
            <strong style="color:<?= $pal[$b] ?? '#6b7280' ?>"><?= risk_band_badge($b, false) ?> <?= e($b) ?></strong>
            <span class="muted"><?= e(money($amt)) ?> · <?= $pct ?>%</span>
          </div>
          <div style="height:12px;background:#f3f4f6;border-radius:8px;overflow:hidden">
            <div style="height:100%;width:<?= $max > 0 ? round(($amt/$max)*100) : 0 ?>%;background:<?= $pal[$b] ?? '#6b7280' ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<section class="panel" style="margin-top:1.25rem">
  <h2 style="margin:0 0 1rem">Detalle de operaciones fondeadas</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Crédito</th><th>Deudor</th><th>Riesgo</th><th>Monto</th><th>Retorno est.</th><th>Tasa</th><th>Plazo</th><th>Estado</th><th>Crédito</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="<?= e(url('/loans/' . $r['loan_id'])) ?>"><strong><?= e($r['loan_code']) ?></strong></a></td>
          <td><?= e($r['borrower_id_code']) ?></td>
          <td><?= risk_band_badge($r['risk_band'] ?? null, false) ?></td>
          <td style="text-align:right"><?= e(money($r['amount'])) ?></td>
          <td style="text-align:right"><?= e(money($r['expected_return'] ?? 0)) ?></td>
          <td><?= e(number_format((float)$r['annual_rate'],1)) ?>%</td>
          <td><?= (int)$r['term_months'] ?> m</td>
          <td><?= e(status_label($r['status'])) ?></td>
          <td><?= e(status_label($r['loan_status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="muted">Todavía no invertiste. Andá al <a href="<?= e(url('/marketplace')) ?>">mercado de créditos</a> para empezar.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
