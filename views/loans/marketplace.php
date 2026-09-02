<?php
/** @var array $loans */
?>
<section class="page-head">
  <div>
    <h1>Mercado de créditos</h1>
    <p class="muted">Elegí a quién fondear. Cada operación la aprobás vos: monto, plazo, TNA y perfil de riesgo.</p>
  </div>
  <a class="btn btn-accent" href="<?= e(url('/loans/create')) ?>">Publicar solicitud</a>
</section>

<div class="loan-grid">
<?php foreach ($loans as $l):
  $pct = (float)$l['principal'] > 0 ? min(100, round(((float)$l['funded_amount'] / (float)$l['principal']) * 100)) : 0;
  $band = (string)($l['risk_band'] ?? '—');
  $per10k = null;
  try {
    $q = invest_quote(10000, (float)$l['annual_rate'], (int)$l['term_months'], $band !== '—' ? $band : 'C', (float)\App\Core\App::config('wallet.platform_fee_pct', 1.5));
    $per10k = $q['expected_interest'];
  } catch (\Throwable $e) {}
?>
  <a class="loan-card" href="<?= e(url('/loans/' . $l['id'])) ?>">
    <div class="loan-card-top">
      <strong><?= e($l['loan_code']) ?></strong>
      <?= risk_band_badge($band, false) ?>
    </div>
    <div class="loan-amount"><?= e(money($l['principal'])) ?></div>
    <div class="muted"><?= e($l['product_name']) ?> · <?= (int)$l['term_months'] ?> m · <?= e(number_format((float)$l['annual_rate'],1)) ?>% TNA</div>
    <div class="muted">Perfil <?= risk_band_badge($band, true) ?></div>
    <?php if ($per10k !== null): ?>
      <div class="muted">Retorno est. / $10.000: <?= e(money($per10k)) ?></div>
    <?php endif; ?>
    <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
    <div class="muted">Fondeado <?= $pct ?>% · Cuota <?= e(money($l['installment_amount'])) ?></div>
  </a>
<?php endforeach; ?>
<?php if (!$loans): ?>
  <p class="muted">No hay créditos abiertos. Sé el primero en publicar uno.</p>
<?php endif; ?>
</div>
