<section class="section legal">
  <h1>Marco regulatorio y avisos (Argentina)</h1>
  <p class="muted">Versión 1.1 · República Argentina · Moneda de operación: pesos (ARS)</p>

  <div class="banner warn">
    <strong>Aviso obligatorio (BCRA – PSCPP):</strong>
    Credimax se limita a ofrecer una plataforma tecnológica para poner en contacto a oferentes y demandantes de crédito entre particulares.
    <strong>No está autorizado a operar como entidad financiera</strong> por el Banco Central de la República Argentina (BCRA).
    <strong>No asume el riesgo crediticio</strong> de las operaciones entre inversores y tomadores, ni garantiza —directa o indirectamente— el cobro de los créditos.
  </div>

  <h2>1. Ámbito territorial</h2>
  <p>La plataforma está dirigida a personas humanas y PyME con residencia/domicilio en la <strong>República Argentina</strong>, mayores de 18 años (o personería jurídica válida), que operan en pesos argentinos (ARS).</p>

  <h2>2. Encaje normativo principal</h2>
  <ul>
    <li><strong>BCRA – PSCPP:</strong> Proveedores de servicios de créditos entre particulares a través de plataformas (Com. “A” 7406 y mods.). Requiere inscripción SEFyC para oferta pública.</li>
    <li><strong>Ley 21.526</strong>: Credimax <strong>no</strong> es entidad financiera ni capta depósitos con garantía del sistema.</li>
    <li><strong>Ley 25.246 / 27.739 y UIF</strong>: PLA/FT; KYC, PEP y controles según sujeto obligado.</li>
    <li><strong>Ley 24.240</strong>: Defensa del Consumidor; transparencia TNA/TEA/CFT; botones de <a href="<?= e(url('/legales/arrepentimiento')) ?>">arrepentimiento</a> y <a href="<?= e(url('/legales/baja')) ?>">baja</a>.</li>
    <li><strong>Ley 25.326</strong>: Protección de Datos Personales.</li>
  </ul>

  <h2>3. Modelo de negocio (finanzas colaborativas)</h2>
  <p>Credimax opera bajo el mismo esquema funcional que las plataformas PSCPP: conecta solicitantes e inversores, publica solicitudes, permite fondeo voluntario, desembolsa al completar el 100% y reparte cuotas. Ver <a href="<?= e(url('/legales/adhesion')) ?>">Adhesión</a> y <a href="<?= e(url('/legales/fideicomiso')) ?>">Fideicomiso / segregación</a>.</p>

  <h2>4. Qué NO es Credimax</h2>
  <ul>
    <li>No es un banco ni una entidad financiera autorizada por el BCRA.</li>
    <li>No ofrece garantía SODESA ni seguro de depósitos (Ley 24.485).</li>
    <li>No garantiza capital ni rendimiento al inversor.</li>
    <li>CVU/alias/QR/DEBIN/ECHEQ internos son del ledger Credimax hasta integración con PSP/banco autorizado.</li>
  </ul>

  <h2>5. Condiciones P2P (alineación BCRA)</h2>
  <ul>
    <li>El <strong>inversor asume el riesgo de crédito</strong> y <strong>aprueba cada fondeo</strong>.</li>
    <li>No hay auto-fondeo ni préstamo con capital propio de la plataforma en modalidad PSCPP.</li>
    <li>En producción, fondos de clientes en cuentas segregadas (banco/PSP) reconciliadas con el ledger.</li>
    <li>Perfiles de riesgo AA–F (personas) y PA–PC (PyME) son internos y orientativos.</li>
  </ul>

  <h2>6. Transparencia</h2>
  <p>Se informan TNA, TEA, CFT TNA y CFT TEA, comisiones y mora. Ver <a href="<?= e(url('/tasas')) ?>">Tasas</a>, <a href="<?= e(url('/costos')) ?>">Costos</a> y <a href="<?= e(url('/estadisticas')) ?>">Estadísticas</a>.</p>

  <h2>7. Cumplimiento pendiente</h2>
  <p>Antes de oferta pública: inscripción PSCPP, UIF, fideicomiso/cuenta segregada efectiva, CENDEU si aplica, personería/CUIT y políticas PLA/FT.</p>

  <p>
    <a href="<?= e(url('/legales/terminos')) ?>">Términos</a> ·
    <a href="<?= e(url('/legales/adhesion')) ?>">Adhesión</a> ·
    <a href="<?= e(url('/legales/usuario-financiero')) ?>">Usuario financiero</a>
  </p>
</section>
