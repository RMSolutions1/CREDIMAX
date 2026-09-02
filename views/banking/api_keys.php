<?php
/** @var array $keys */
/** @var string|null $newKey */
?>
<section class="page-head"><h1>API Keys</h1><p class="muted">Para integraciones machine-to-machine sobre la API privada.</p></section>

<?php if (!empty($newKey)): ?>
  <div class="banner warn"><strong>API Key (única vez):</strong><br><code><?= e($newKey) ?></code></div>
<?php endif; ?>

<div class="grid-2">
  <section class="panel">
    <h2>Crear key</h2>
    <form method="post" action="<?= e(url('/banking/api-keys')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Nombre</label>
      <input name="name" value="Integración" required>
      <button class="btn btn-accent" type="submit">Generar</button>
    </form>
    <p class="muted">Auth principal de la API: <code>POST /api/v1/login/jwt</code> → header <code>Authorization: JWT &lt;token&gt;</code></p>
  </section>
  <section class="panel">
    <h2>Keys existentes</h2>
    <div class="list">
      <?php foreach ($keys as $k): ?>
        <div class="list-item">
          <div>
            <strong><?= e($k['name']) ?></strong>
            <div class="muted"><?= e($k['api_key_prefix']) ?>… · <?= e($k['created_at']) ?><?= $k['revoked_at'] ? ' · REVOCADA' : '' ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$keys): ?><p class="muted">Sin keys.</p><?php endif; ?>
    </div>
  </section>
</div>
