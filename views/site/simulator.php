<?php
/** @var array $products */
?>
<section class="section">
  <h1>Simulador de crédito</h1>
  <p class="section-lead">Calculá cuota, TNA, TEA, CFT TNA y CFT TEA (con IVA). Pedís un monto y lo recibís completo: la comisión se suma a las cuotas.</p>
  <div class="grid-2">
    <section class="panel">
      <form id="sim-form" class="form" onsubmit="return false;">
        <label>Producto</label>
        <select id="sim-product">
          <?php foreach ($products as $p): ?>
            <option
              value="<?= (int)$p['id'] ?>"
              data-rate="<?= e((string)$p['annual_rate']) ?>"
              data-fee="<?= e((string)$p['origination_fee_pct']) ?>"
              data-min="<?= e((string)$p['min_amount']) ?>"
              data-max="<?= e((string)$p['max_amount']) ?>"
              data-tmin="<?= (int)$p['min_term_months'] ?>"
              data-tmax="<?= (int)$p['max_term_months'] ?>"
            ><?= e($p['name']) ?> (TNA <?= e(number_format((float)$p['annual_rate'],1)) ?>%)</option>
          <?php endforeach; ?>
        </select>
        <label>Monto que querés recibir</label>
        <input id="sim-amount" type="number" value="1000000" min="1000" step="1000">
        <label>Plazo (meses)</label>
        <input id="sim-months" type="number" value="12" min="1" max="48">
        <button class="btn btn-accent" type="button" id="sim-run">Calcular</button>
      </form>
    </section>
    <section class="panel" id="sim-result">
      <p class="muted">Completá y calculá para ver el detalle.</p>
    </section>
  </div>
</section>
<script>
(function(){
  const base = <?= json_encode(url('/api/simulator')) ?>;
  const product = document.getElementById('sim-product');
  const amount = document.getElementById('sim-amount');
  const months = document.getElementById('sim-months');
  const out = document.getElementById('sim-result');
  function money(n){
    return Number(n).toLocaleString('es-AR',{minimumFractionDigits:2, maximumFractionDigits:2});
  }
  function sync(){
    const o = product.options[product.selectedIndex];
    if (!o) return;
    amount.min = o.dataset.min; amount.max = o.dataset.max;
    months.min = o.dataset.tmin; months.max = o.dataset.tmax;
  }
  async function run(){
    sync();
    const o = product.options[product.selectedIndex];
    const url = base + '?amount=' + encodeURIComponent(amount.value)
      + '&months=' + encodeURIComponent(months.value)
      + '&rate=' + encodeURIComponent(o.dataset.rate)
      + '&fee=' + encodeURIComponent(o.dataset.fee);
    const res = await fetch(url); const data = await res.json();
    if (!data.ok) { out.innerHTML = '<p class="muted">Error de cálculo</p>'; return; }
    out.innerHTML = '<h2>Resultado</h2>'
      + '<ul class="kv">'
      + '<li><span>Vas a recibir</span><strong>$ ' + money(data.disbursement) + '</strong></li>'
      + '<li><span>Comisión (financiada)</span><strong>$ ' + money(data.origination_fee) + '</strong></li>'
      + '<li><span>Capital financiado</span><strong>$ ' + money(data.financed_principal) + '</strong></li>'
      + '<li><span>Cuota (sin IVA)</span><strong>$ ' + money(data.installment) + '</strong></li>'
      + '<li><span>1ª cuota c/ IVA est.</span><strong>$ ' + money(data.installment_with_iva) + '</strong></li>'
      + '<li><span>Total a pagar (sin IVA)</span><strong>$ ' + money(data.total_payable) + '</strong></li>'
      + '<li><span>Total c/ IVA est.</span><strong>$ ' + money(data.total_payable_with_iva) + '</strong></li>'
      + '<li><span>TNA fija</span><strong>' + data.tna + '%</strong></li>'
      + '<li><span>TEA</span><strong>' + data.tea + '%</strong></li>'
      + '<li><span>CFT TNA</span><strong>' + data.cft_tna + '%</strong></li>'
      + '<li class="rate-highlight"><span>CFT TEA</span><strong>' + data.cft_tea + '%</strong></li>'
      + '</ul>'
      + '<p class="muted">CFT TEA incluye comisión e IVA ' + data.iva_pct + '% sobre intereses y comisión (criterio BCRA / exposición tipo BNA). Recibís el 100% del monto pedido. Valores orientativos; el contrato confirma las condiciones finales.</p>'
      + '<a class="btn btn-accent" href="<?= e(url('/register')) ?>">Continuar solicitud</a>';
  }
  product.addEventListener('change', sync);
  document.getElementById('sim-run').addEventListener('click', run);
  sync();
})();
</script>
