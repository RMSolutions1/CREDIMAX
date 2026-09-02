<section class="section">
  <h1>Botón de arrepentimiento</h1>
  <p class="section-lead">Derecho del consumidor (Ley 24.240) a desistir de la contratación a distancia de servicios de la plataforma, dentro de los plazos legales aplicables.</p>

  <?php if (!auth_user()): ?>
    <div class="panel">
      <p>Para ejercer el arrepentimiento sobre tu cuenta Credimax, <a href="<?= e(url('/login')) ?>">ingresá</a> y completá el formulario.</p>
      <p class="muted">También podés escribir a <a href="<?= e(url('/contacto')) ?>">Contacto</a>.</p>
    </div>
  <?php else: ?>
    <section class="panel narrow">
      <form method="post" action="<?= e(url('/legales/arrepentimiento')) ?>" class="form">
        <?= csrf_field() ?>
        <label>Motivo (opcional)</label>
        <textarea name="reason" rows="3" placeholder="Breve descripción"></textarea>
        <p class="muted">No podés ejercer la baja automática si tenés créditos o fondeos activos. En ese caso, contactá soporte.</p>
        <button class="btn btn-accent" type="submit">Registrar arrepentimiento</button>
      </form>
    </section>
  <?php endif; ?>

  <p class="muted"><a href="<?= e(url('/legales/baja')) ?>">Botón de baja de cuenta</a> · <a href="<?= e(url('/legales/defensa-consumidor')) ?>">Defensa del Consumidor</a> · <a href="https://www.argentina.gob.ar/produccion/defensadelconsumidor/formulario" target="_blank" rel="noopener">Formulario oficial</a></p>
</section>
