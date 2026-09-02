<?php
/** @var array $profile */
/** @var array $docs */
?>
<section class="page-head">
  <div>
    <h1>Verificación de identidad</h1>
    <p class="muted">Estado actual: <strong><?= e(status_label($profile['kyc_status'])) ?></strong></p>
  </div>
</section>

<?php if (!empty($profile['kyc_notes'])): ?>
  <div class="banner warn">Notas: <?= e($profile['kyc_notes']) ?></div>
<?php endif; ?>

<section class="panel narrow">
  <p class="muted">Subí DNI frente, DNI dorso y selfie. Formatos: JPG, PNG, WEBP o PDF (máx. 5 MB).</p>
  <form method="post" action="<?= e(url('/kyc')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <label>DNI frente</label>
    <input type="file" name="dni_front" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <label>DNI dorso</label>
    <input type="file" name="dni_back" accept=".jpg,.jpeg,.png,.webp,.pdf">
    <label>Selfie</label>
    <input type="file" name="selfie" accept=".jpg,.jpeg,.png,.webp">
    <button class="btn btn-accent" type="submit">Enviar a revisión</button>
  </form>
</section>

<?php if ($docs): ?>
<section class="panel">
  <h2>Documentos enviados</h2>
  <div class="list">
    <?php foreach ($docs as $d): ?>
      <div class="list-item">
        <div><strong><?= e($d['doc_type']) ?></strong><div class="muted"><?= e($d['created_at']) ?></div></div>
        <span><?= e(status_label($d['status'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
