<?php
/** @var array $profile */
/** @var array $docs */
/** @var array $score */
?>
<section class="page-head">
  <div>
    <h1>Verificación de identidad (KYC)</h1>
    <p class="muted">Paso 5 de 5 · DNI + selfie · Score preliminar <?= e($score['band'] ?? '—') ?> (<?= (int)($score['score'] ?? 0) ?>/100)</p>
  </div>
</section>
<section class="panel narrow">
  <p>Estado: <strong><?= e(status_label($profile['kyc_status'] ?? 'pending')) ?></strong>. Monto sugerido orientativo: <?= e(money($score['max_suggested'] ?? 0)) ?>.</p>
  <form method="post" action="<?= e(url('/onboarding/kyc')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <label>DNI frente (obligatorio)</label>
    <input type="file" name="dni_front" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <label>DNI dorso (obligatorio)</label>
    <input type="file" name="dni_back" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <label>Selfie (obligatorio)</label>
    <input type="file" name="selfie" accept=".jpg,.jpeg,.png,.webp">
    <label>Prueba de domicilio (recomendado)</label>
    <input type="file" name="proof_address" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <label>Constancia de ingresos (recomendado)</label>
    <input type="file" name="proof_income" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <button class="btn btn-accent" type="submit">Enviar a revisión</button>
  </form>
</section>
<?php if ($docs): ?>
<section class="panel">
  <h2>Documentos cargados</h2>
  <div class="list">
    <?php foreach ($docs as $d): ?>
      <div class="list-item"><div><strong><?= e($d['doc_type']) ?></strong><div class="muted"><?= e($d['created_at']) ?></div></div><span><?= e(status_label($d['status'])) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
