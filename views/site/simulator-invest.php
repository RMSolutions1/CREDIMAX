<?php
/** @var array $bands */
/** @var array $labels */
?>
<section class="section">
  <h1>Simulador de inversión</h1>
  <p class="section-lead">Estimá el retorno orientativo según perfil de riesgo y plazo. No es oferta ni garantía de rentabilidad.</p>
  <div class="banner warn">Invertir en créditos entre personas implica riesgo de incobrabilidad. El retorno efectivo puede ser positivo o negativo.</div>

  <div class="grid-2">
    <section class="panel">
      <form id="inv-form" class="form" onsubmit="return false;">
        <label>Perfil de riesgo</label>
        <select id="inv-band">
          <?php foreach ($bands as $b => $tna): ?>
            <option value="<?= e($b) ?>" data-rate="<?= e((string)$tna) ?>" <?= $b === 'C' ? 'selected' : '' ?>>
              <?= e($b) ?> — <?= e($labels[$b] ?? $b) ?> (TNA ref. <?= e(number_format((float)$tna,1)) ?>%)
            </option>
          <?php endforeach; ?>
        </select>
        <label>Monto a invertir</label>
        <input id="inv-amount" type="number" value="100000" min="5000" step="1000">
        <label>Plazo (meses)</label>
        <select id="inv-months">
          <?php foreach ([3,6,12,18,24,36,48] as $m): ?>
            <option value="<?= $m ?>" <?= $m === 12 ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-accent" type="button" id="inv-run">Simular</button>
      </form>
    </section>
    <section class="panel" id="inv-result">
      <p class="muted">Completá y simulá para ver el resultado.</p>
    </section>
  </div>
</section>
<script>
(function(){
  const base = <?= json_encode(url('/api/simulator-inversion')) ?>;
  const band = document.getElementById('inv-band');
  const amount = document.getElementById('inv-amount');
  const months = document.getElementById('inv-months');
  const out = document.getElementById('inv-result');
  function money(n){ return Number(n).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  async function run(){
    const o = band.options[band.selectedIndex];
    const url = base + '?amount=' + encodeURIComponent(amount.value)
      + '&months=' + encodeURIComponent(months.value)
      + '&band=' + encodeURIComponent(band.value)
      + '&rate=' + encodeURIComponent(o.dataset.rate);
    const res = await fetch(url); const data = await res.json();
    if (!data.ok) { out.innerHTML = '<p class="muted">Error de cálculo</p>'; return; }
    out.innerHTML = '<h2>Resultado</h2><ul class="kv">'
      + '<li><span>Perfil</span><strong>' + data.band + ' · ' + (data.band_label||'') + '</strong></li>'
      + '<li><span>TNA ref.</span><strong>' + data.tna + '%</strong></li>'
      + '<li><span>TEA ref.</span><strong>' + data.tea + '%</strong></li>'
      + '<li><span>Interés est. (neto comisión)</span><strong>$ ' + money(data.expected_interest) + '</strong></li>'
      + '<li class="rate-highlight"><span>Total est. al final</span><strong>$ ' + money(data.expected_total) + '</strong></li>'
      + '<li><span>Cuota est. del crédito</span><strong>$ ' + money(data.monthly_approx) + '</strong></li>'
      + '</ul><p class="muted">' + data.disclaimer + '</p>'
      + '<a class="btn btn-accent" href="<?= e(url('/register')) ?>">Quiero invertir</a>';
  }
  document.getElementById('inv-run').addEventListener('click', run);
})();
</script>
