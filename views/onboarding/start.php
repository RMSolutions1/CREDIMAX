<?php
/** @var array $profile */
?>
<section class="page-head">
  <div>
    <h1>Completá tu cuenta</h1>
    <p class="muted">Onboarding Credimax: datos → contacto → laboral → PEP → KYC.</p>
  </div>
</section>
<?php
$step = $profile['onboarding_step'] ?? 'start';
$steps = [
  'personal' => ['Datos personales', '/onboarding/personal'],
  'contact' => ['Verificar contacto', '/onboarding/contacto'],
  'employment' => ['Situación laboral', '/onboarding/laboral'],
  'pep' => ['PEP y legales', '/onboarding/pep'],
  'kyc' => ['Verificación ID', '/onboarding/kyc'],
];
?>
<section class="panel">
  <div class="onboard-steps">
    <?php foreach ($steps as $key => $s): ?>
      <a class="onboard-step <?= $step === $key || $step === 'done' ? 'done' : '' ?>" href="<?= e(url($s[1])) ?>"><?= e($s[0]) ?></a>
    <?php endforeach; ?>
  </div>
  <p>Estado KYC: <strong><?= e(status_label($profile['kyc_status'] ?? 'pending')) ?></strong>
    <?php if (!empty($profile['risk_band'])): ?> · Score <?= e($profile['risk_band']) ?> (<?= (int)($profile['risk_score'] ?? 0) ?>/100)<?php endif; ?>
  </p>
  <div class="actions">
    <a class="btn btn-accent" href="<?= e(url('/onboarding/personal')) ?>">Continuar</a>
    <a class="btn" href="<?= e(url('/dashboard')) ?>">Ir al panel</a>
  </div>
</section>
