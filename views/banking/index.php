<?php
/** @var array $accounts */
/** @var array $wallet */
?>
<section class="page-head">
  <div>
    <h1>Credimax Bank Privado</h1>
    <p class="muted">Cuenta de pagos interna Credimax · identificadores propios · liquidación en ledger</p>
  </div>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/banking/transfer')) ?>">Transferir</a>
    <a class="btn" href="<?= e(url('/api/docs')) ?>">API Docs</a>
  </div>
</section>

<?php $acc = $accounts[0] ?? null; ?>
<div class="stat-grid">
  <div class="stat"><span>Disponible</span><strong><?= e(money($wallet['available_balance'] ?? 0)) ?></strong></div>
  <div class="stat"><span>CVU</span><strong style="font-size:1rem"><?= e($wallet['cvu'] ?? '—') ?></strong></div>
  <div class="stat"><span>Alias</span><strong style="font-size:1rem"><?= e($wallet['alias'] ?? '—') ?></strong></div>
  <div class="stat"><span>Account ID</span><strong style="font-size:1rem"><?= e($wallet['account_code'] ?? '—') ?></strong></div>
</div>

<div class="grid-2">
  <section class="panel">
    <h2>Cuenta</h2>
    <?php if ($acc): ?>
      <ul class="kv">
        <li><span>Tipo</span><strong><?= e($acc['type']) ?></strong></li>
        <li><span>Estado</span><strong><?= e($acc['status']) ?></strong></li>
        <li><span>Titular</span><strong><?= e($acc['owners'][0]['display_name'] ?? '') ?></strong></li>
        <li><span>Banco</span><strong>Credimax Bank Privado (<?= e($acc['bank_id']) ?>)</strong></li>
      </ul>
    <?php endif; ?>
    <div class="actions">
      <a class="btn" href="<?= e(url('/banking/alias')) ?>">Cambiar alias</a>
      <a class="btn" href="<?= e(url('/wallet/qr')) ?>">QR</a>
    </div>
  </section>
  <section class="panel">
    <h2>Operaciones</h2>
    <div class="list">
      <a class="list-item" href="<?= e(url('/banking/transfer')) ?>"><strong>Transferencias CVU/Alias</strong><span>→</span></a>
      <a class="list-item" href="<?= e(url('/banking/debin')) ?>"><strong>DEBIN (cobros)</strong><span>→</span></a>
      <a class="list-item" href="<?= e(url('/banking/echeq')) ?>"><strong>ECHEQ</strong><span>→</span></a>
      <a class="list-item" href="<?= e(url('/banking/api-keys')) ?>"><strong>Credenciales API JWT</strong><span>→</span></a>
    </div>
  </section>
</div>
