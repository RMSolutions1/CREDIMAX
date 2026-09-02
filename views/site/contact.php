<section class="section">
  <h1>Contacto</h1>
  <p class="section-lead">Escribinos. Respondemos consultas de cuenta, KYC, créditos e inversiones.</p>
  <section class="panel narrow">
    <form method="post" action="<?= e(url('/contacto')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Nombre</label>
      <input name="name" required value="<?= e(auth_user()['first_name'] ?? '') ?>">
      <label>Email</label>
      <input type="email" name="email" required value="<?= e(auth_user()['email'] ?? '') ?>">
      <label>Asunto</label>
      <input name="subject" value="Consulta Credimax">
      <label>Mensaje</label>
      <textarea name="message" rows="5" required></textarea>
      <button class="btn btn-accent" type="submit">Enviar</button>
    </form>
  </section>
</section>
