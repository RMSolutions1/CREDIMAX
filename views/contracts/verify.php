<?php
/** @var array $contract */
/** @var array|null $user */
?>
<section class="page-head"><h1>Verificación de contrato</h1></section>
<section class="panel narrow">
  <p class="muted">Este enlace permite comprobar que el contrato corresponde a un crédito registrado en Credimax.</p>
  <ul class="kv">
    <li><span>Código</span><strong><?= e((string) ($contract['loan_code'] ?? '')) ?></strong></li>
    <li><span>Estado</span><strong><?= e(status_label((string) ($contract['status'] ?? ''))) ?></strong></li>
    <li><span>Capital</span><strong><?= money((float) ($contract['principal'] ?? 0)) ?></strong></li>
    <li><span>Cuota</span><strong><?= money((float) ($contract['installment_amount'] ?? 0)) ?></strong></li>
    <?php if ($user): ?>
      <li><span>Deudor</span><strong><?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></strong></li>
    <?php endif; ?>
  </ul>
  <p><span class="badge">Contrato válido</span></p>
</section>
