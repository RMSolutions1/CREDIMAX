<?php /** @var array $bands */ ?>
<section class="section">
  <h1>Invertí en créditos entre personas</h1>
  <p class="section-lead">Poné tu plata a trabajar en préstamos reales. Elegís el riesgo. Aprobás cada operación. Competimos con control y datos, no con promesas garantizadas.</p>
  <div class="banner warn">El retorno no está garantizado. Invertir implica riesgo de incobrabilidad. Credimax no es entidad financiera ni asegura el cobro.</div>

  <div class="stat-grid">
    <div class="stat"><span>Inversión mínima sugerida</span><strong>$5.000</strong></div>
    <div class="stat"><span>Perfiles</span><strong>AA–F / PA–PC</strong></div>
    <div class="stat"><span>Plazos</span><strong>3 a 48 m</strong></div>
    <div class="stat"><span>Fondeo</span><strong>Decisión tuya</strong></div>
  </div>

  <div class="feature-grid" style="margin-top:1.5rem">
    <article class="feature"><h3>Mercado vivo</h3><p>Ves TNA, banda de riesgo y retorno estimado por $10.000 antes de fondear.</p></article>
    <article class="feature"><h3>Diversificá</h3><p>Participá en varios créditos. El cobro se prorratea automáticamente al pagarse cada cuota.</p></article>
    <article class="feature"><h3>Sin auto-colocación</h3><p>Alineado a PSCPP: nadie presta tu plata sin tu OK explícito.</p></article>
  </div>

  <section class="panel" style="margin-top:1.5rem">
    <h2>TNA de referencia por perfil</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Perfil</th><th>Etiqueta</th><th>TNA ref.</th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b => $tna): ?>
          <tr>
            <td><strong><?= e((string)$b) ?></strong></td>
            <td><?= e(\App\Services\ScoringService::bandLabel((string)$b)) ?></td>
            <td><?= e(number_format((float)$tna, 1)) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="actions" style="margin-top:1.2rem">
    <a class="btn btn-accent" href="<?= e(url('/simulador-inversion')) ?>">Simular retorno</a>
    <a class="btn" href="<?= e(url('/marketplace')) ?>">Ver mercado</a>
    <a class="btn" href="<?= e(url('/register')) ?>">Quiero invertir</a>
  </div>
</section>
