<?php /** @var bool $authed */ ?>
<section class="section">
  <h1>Botón de baja</h1>
  <p class="section-lead">Solicitá el cierre definitivo de tu cuenta Credimax.</p>

  <?php if (!$authed): ?>
    <div class="panel">
      <p><a href="<?= e(url('/login')) ?>">Ingresá</a> para dar de baja tu cuenta, o escribinos por <a href="<?= e(url('/contacto')) ?>">Contacto</a>.</p>
    </div>
  <?php else: ?>
    <section class="panel narrow">
      <form method="post" action="<?= e(url('/legales/baja')) ?>" class="form">
        <?= csrf_field() ?>
        <label>Motivo</label>
        <input name="reason" placeholder="Ya no quiero operar">
        <label class="check"><input type="checkbox" name="confirm_close" value="1" required> Confirmo que retiré mi saldo y no tengo operaciones activas</label>
        <button class="btn btn-accent" type="submit">Dar de baja mi cuenta</button>
      </form>
    </section>
  <?php endif; ?>
</section>
