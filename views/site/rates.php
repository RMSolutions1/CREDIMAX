<?php
/** @var list<array{product: array, quote: ?array, ref_amount: float, ref_months: int}> $rate_rows */
/** @var float $ref_amount */
/** @var int $ref_months */
?>
<section class="section">
  <h1>Tasas y costos</h1>
  <p class="section-lead">Publicamos TNA, TEA, CFT TNA y CFT TEA (con IVA) para que compares de verdad. Referencia: <?= e(money($ref_amount)) ?> a <?= (int)$ref_months ?> meses. El contrato confirma los valores finales.</p>
  <div class="banner ok-soft" style="margin-bottom:1rem">
    Competimos con tasas desde TNA 36%. Ejemplo de mercado Credimax: $500.000 a 12 meses con TNA 48% → CFT TEA ~86,9% y desembolso del 100%.
  </div>

  <div class="table-wrap panel">
    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th>TNA fija</th>
          <th>TEA</th>
          <th>CFT TNA</th>
          <th class="rate-highlight">CFT TEA</th>
          <th>Comisión</th>
          <th>Mora</th>
          <th>Plazo</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rate_rows as $row):
        $p = $row['product'];
        $q = $row['quote'];
      ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td><?= e(number_format((float)$p['annual_rate'], 2)) ?>%</td>
          <td><?= $q ? e(number_format((float)$q['tea'], 2)) . '%' : '—' ?></td>
          <td><?= $q ? e(number_format((float)$q['cft_tna'], 2)) . '%' : '—' ?></td>
          <td class="rate-highlight"><strong><?= $q ? e(number_format((float)$q['cft_tea'], 2)) . '%' : '—' ?></strong></td>
          <td><?= e(number_format((float)$p['origination_fee_pct'], 2)) ?>%</td>
          <td><?= e(number_format((float)$p['late_fee_pct'], 2)) ?>%</td>
          <td><?= (int)$p['min_term_months'] ?>–<?= (int)$p['max_term_months'] ?> m</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel" style="margin-top:1.25rem">
    <h2>Cómo se calculan</h2>
    <ul class="muted" style="margin:0;padding-left:1.2rem;line-height:1.6">
      <li><strong>TNA</strong> — tasa nominal anual pactada (interés compensatorio).</li>
      <li><strong>TEA</strong> — <code>(1 + TNA/12)¹² − 1</code> (efectiva anual solo por interés).</li>
      <li><strong>CFT TNA / CFT TEA</strong> — incluyen comisión de originación financiada e IVA 21% sobre intereses y comisión (consumidor final), vía TIR del flujo de cuotas vs. monto recibido.</li>
      <li>Sistema francés (cuota fija). Tasa <strong>fija</strong> durante el plazo del crédito.</li>
    </ul>
    <p class="muted" style="margin-top:1rem">Referencia de mercado: en el segmento PSCPP hay ofertas con TNA 110%+. Credimax publica productos desde 36–68% TNA según línea. Usá el <a href="<?= e(url('/simulador')) ?>">simulador</a> y <a href="<?= e(url('/por-que-credimax')) ?>">por qué Credimax</a>.</p>
    <p class="muted">Usá el <a href="<?= e(url('/simulador')) ?>">simulador</a> con tu monto y plazo.</p>
  </div>
</section>
