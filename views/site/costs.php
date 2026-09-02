<section class="section">
  <h1>Costos de nuestros servicios</h1>
  <p class="section-lead">Las comisiones integran el CFT informado en simulador y contrato. Pueden actualizarse; prevalece lo aceptado al publicar/fondear.</p>
  <div class="table-wrap panel">
    <table>
      <thead><tr><th>Concepto</th><th>Quién lo paga</th><th>Detalle</th></tr></thead>
      <tbody>
        <tr>
          <td>Comisión de originación</td>
          <td>Solicitante</td>
          <td>% del monto (producto). Se capitaliza en cuotas; el cliente recibe el 100% pedido.</td>
        </tr>
        <tr>
          <td>Interés compensatorio (TNA)</td>
          <td>Solicitante → inversores</td>
          <td>Tasa fija del producto / perfil. Sistema francés.</td>
        </tr>
        <tr>
          <td>Comisión de plataforma sobre intereses</td>
          <td>Inversor (estimación)</td>
          <td>Hasta <?= e(number_format((float)\App\Core\App::config('wallet.platform_fee_pct', 1.5), 2)) ?>% del interés estimado en simulador de inversión.</td>
        </tr>
        <tr>
          <td>Mora</td>
          <td>Solicitante</td>
          <td>% informado en producto (aplicación operativa según manual).</td>
        </tr>
        <tr>
          <td>IVA</td>
          <td>Según normativa</td>
          <td>Incluido en CFT TEA estimado (21% consumidor final sobre interés/comisión).</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="muted"><a href="<?= e(url('/tasas')) ?>">Tasas vigentes</a> · <a href="<?= e(url('/simulador')) ?>">Simulador crédito</a> · <a href="<?= e(url('/simulador-inversion')) ?>">Simulador inversión</a></p>
</section>
