<?php
/** @var array $wallet */
?>
<section class="page-head"><h1>Alias CVU</h1></section>
<section class="panel narrow">
  <ul class="kv">
    <li><span>CVU actual</span><strong><?= e($wallet['cvu'] ?? '') ?></strong></li>
    <li><span>Alias actual</span><strong><?= e($wallet['alias'] ?? '') ?></strong></li>
  </ul>
  <form method="post" action="<?= e(url('/banking/alias')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Nuevo alias</label>
    <input name="alias" required minlength="6" maxlength="40" placeholder="mi.negocio.credimax">
    <button class="btn btn-accent" type="submit">Guardar alias</button>
  </form>
</section>
