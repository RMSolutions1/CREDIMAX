<?php
/** @var array $profile */
?>
<section class="page-head"><h1>PEP y aceptación legal</h1><p class="muted">Paso 4 de 5</p></section>
<section class="panel narrow">
  <form method="post" action="<?= e(url('/onboarding/pep')) ?>" class="form">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="is_pep" value="1" <?= !empty($profile['is_pep'])?'checked':'' ?>> Soy Persona Expuesta Políticamente (PEP) o relacionado/a</label>
    <label>Detalle PEP (si aplica)</label>
    <input name="pep_detail" value="<?= e($profile['pep_detail'] ?? '') ?>">
    <label class="check"><input type="checkbox" name="accept_terms" value="1" required> Acepto los <a href="<?= e(url('/legales/terminos')) ?>" target="_blank">Términos y Condiciones</a> y el <a href="<?= e(url('/legales/contrato-credito')) ?>" target="_blank">Contrato de crédito</a></label>
    <label class="check"><input type="checkbox" name="accept_privacy" value="1" required> Acepto la <a href="<?= e(url('/legales/privacidad')) ?>" target="_blank">Política de Privacidad</a></label>
    <p class="muted">También declaro conocer el <a href="<?= e(url('/legales/manual-operativo')) ?>" target="_blank">Manual operativo P2P</a> y los riesgos de inversión/crédito.</p>
    <button class="btn btn-accent" type="submit">Continuar a KYC</button>
  </form>
</section>
