<?php
/** @var array $stats */
$brand = \App\Core\App::config('app_name', 'Credimax');
$volume    = (float) ($stats['volume_credits'] ?? 0);
$granted   = (int)   ($stats['loans_granted']  ?? 0);
$open      = (int)   ($stats['open_requests']  ?? 0);
$investors = (int)   ($stats['investors']      ?? 0);

$heroSlides = [
    'https://images.unsplash.com/photo-1521791136064-7986c2920216?auto=format&fit=crop&w=1800&h=1012&q=85',
    'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=1800&h=1012&q=85',
    'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1800&h=1012&q=85',
];
$heroMain     = 'https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?auto=format&fit=crop&w=1400&h=1050&q=85';
$splitBorrow  = 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&w=1400&h=1050&q=85';
$splitInvest  = 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=1400&h=1050&q=85';
$nonce = csp_nonce();
?>
<section class="hero hero-compete hero-leader">
  <div class="hero-slider" aria-hidden="true">
    <?php foreach ($heroSlides as $i => $src): ?>
      <div class="hero-slide <?= $i === 0 ? 'is-active' : '' ?>" style="background-image:url('<?= e($src) ?>')"></div>
    <?php endforeach; ?>
  </div>
  <div class="hero-copy">
    <p class="eyebrow">
      <i class="fa-solid fa-arrow-trend-up"></i>
      Argentina · Plataforma P2P regulada · En pesos
    </p>
    <h1 class="hero-brand">Crédito y ahorro, con la <em>confianza</em> de un banco.</h1>
    <p class="hero-lead">
      Pedí y recibí el 100% del monto. Invertí eligiendo cada operación. Gestioná tu dinero, tus cuotas y tu portafolio en una sola cuenta <?= e($brand) ?> — 100% digital, con CFT transparente a la vista.
    </p>
    <div class="hero-cta">
      <a class="btn btn-accent btn-lg" href="<?= e(url('/simulador')) ?>">
        <i class="fa-solid fa-calculator"></i> Simular mi préstamo
      </a>
      <a class="btn btn-ghost btn-lg" href="<?= e(url('/register')) ?>">
        <i class="fa-solid fa-user-plus"></i> Abrir mi cuenta gratis
      </a>
    </div>
    <ul class="trust-pills" aria-label="Beneficios clave">
      <li><i class="fa-solid fa-money-bill-trend-up"></i> Desembolso 100%</li>
      <li><i class="fa-solid fa-chart-pie"></i> CFT a la vista</li>
      <li><i class="fa-solid fa-wallet"></i> Billetera + Mercado Pago</li>
      <li><i class="fa-solid fa-credit-card"></i> Hasta $5.000.000</li>
      <li><i class="fa-solid fa-id-card"></i> Identidad verificada</li>
    </ul>
    <div class="trust-logos">
      <span><i class="fa-solid fa-peso-sign"></i> Operatoria en pesos</span>
      <span><i class="fa-solid fa-shield-halved"></i> KYC / AML</span>
      <span><i class="fa-solid fa-landmark"></i> CFT BCRA</span>
      <span><i class="fa-solid fa-scale-balanced"></i> Modelo PSCPP</span>
    </div>
  </div>
  <div class="hero-stage" aria-hidden="true">
    <div class="hero-visual">
      <img src="<?= e($heroMain) ?>" alt="Personas abriendo su cuenta Credimax" loading="eager" fetchpriority="high" />
      <div class="hero-float-card pos-tl fc-check">
        <span class="fa-stack"><i class="fa-solid fa-circle fa-stack-2x"></i><i class="fa-solid fa-check fa-stack-1x"></i></span>
        <div>
          <strong>Cuenta aprobada</strong>
          <span>Verificación biométrica OK</span>
        </div>
      </div>
      <div class="hero-float-card pos-tr fc-rate">
        <span class="fa-stack"><i class="fa-solid fa-circle fa-stack-2x"></i><i class="fa-solid fa-percent fa-stack-1x"></i></span>
        <div>
          <strong>TNA desde 36%</strong>
          <span>Sin sorpresas en el CFT</span>
        </div>
      </div>
      <div class="hero-float-card pos-br fc-shield">
        <span class="fa-stack"><i class="fa-solid fa-circle fa-stack-2x"></i><i class="fa-solid fa-lock fa-stack-1x"></i></span>
        <div>
          <strong>Datos protegidos</strong>
          <span>AES-256 + KYC cifrado</span>
        </div>
      </div>
    </div>
  </div>
  <div class="hero-dots" role="tablist" aria-label="Slider hero">
    <?php foreach ($heroSlides as $i => $_src): ?>
      <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-hero-idx="<?= (int)$i ?>" aria-label="Slide <?= $i+1 ?>"></button>
    <?php endforeach; ?>
  </div>
</section>

<section class="trust-bar" data-reveal>
  <div class="trust-bar-inner">
    <p><i class="fa-solid fa-flag" style="color:var(--brand-2);margin-right:.45rem"></i><strong>Hecho en Argentina, para Argentina.</strong> Residentes. Pesos. Costos claros. Sin letra chica de prestamista informal.</p>
    <div class="trust-bar-links">
      <a href="<?= e(url('/legales/cumplimiento')) ?>"><i class="fa-solid fa-gavel"></i> Marco regulatorio</a>
      <a href="<?= e(url('/seguridad')) ?>"><i class="fa-solid fa-shield-halved"></i> Seguridad</a>
      <a href="<?= e(url('/estadisticas')) ?>"><i class="fa-solid fa-chart-simple"></i> Números en vivo</a>
    </div>
  </div>
</section>

<div class="logo-strip" data-reveal>
  <p>Con la confianza de miles de familias y PyMEs argentinas</p>
  <div class="logo-strip-grid" aria-label="Certificaciones">
    <span><i class="fa-solid fa-building-columns"></i> BCRA Transparencia</span>
    <span><i class="fa-solid fa-user-shield"></i> UIF / PEPs</span>
    <span><i class="fa-solid fa-lock"></i> AES-256 en reposo</span>
    <span><i class="fa-solid fa-cloud"></i> Datos alojados en AR</span>
    <span><i class="fa-solid fa-file-contract"></i> Ley 25.506 firma electrónica</span>
  </div>
</div>

<section class="section" data-reveal>
  <div class="banner warn">
    <i class="fa-solid fa-circle-info" style="margin-right:.4rem"></i>
    Plataforma tecnológica de créditos entre particulares (modelo PSCPP).
    No está autorizada a operar como entidad financiera por el BCRA y no garantiza el cobro.
    Sin seguro de depósitos (Ley 24.485).
    <a href="<?= e(url('/legales/cumplimiento')) ?>">Leer el marco regulatorio completo</a>.
  </div>

  <div class="stat-grid home-stats" style="margin-top:.5rem">
    <div class="stat">
      <i class="fa-solid fa-hand-holding-dollar"></i><br>
      <span>Monto originado</span>
      <strong data-count="<?= (int) round($volume) ?>"><?= e(money($volume)) ?></strong>
    </div>
    <div class="stat">
      <i class="fa-solid fa-handshake"></i><br>
      <span>Préstamos otorgados</span>
      <strong><?= e(number_format($granted, 0, ',', '.')) ?></strong>
    </div>
    <div class="stat">
      <i class="fa-solid fa-chart-line"></i><br>
      <span>Inversores activos</span>
      <strong><?= e(number_format($investors, 0, ',', '.')) ?></strong>
    </div>
    <div class="stat">
      <i class="fa-solid fa-list-check"></i><br>
      <span>Oportunidades abiertas</span>
      <strong><?= $open ?></strong>
    </div>
  </div>
</section>

<section class="section section-alt" data-reveal>
  <div class="section-head">
    <p class="section-eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i> Simulá y decidí</p>
    <h2 class="section-title">Calculá tu cuota <em>antes</em> de pedir</h2>
    <p class="section-lead">Monto, TNA, plazo, IVA, gastos y CFT TEA. El mismo estándar que vas a ver firmado en tu contrato.</p>
  </div>
  <div class="panel mini-sim" id="home-sim">
    <form class="form mini-sim-form" onsubmit="return false;">
      <div class="form-row">
        <div>
          <label>Monto a recibir</label>
          <input id="hs-amount" type="number" value="500000" min="5000" step="10000">
        </div>
        <div>
          <label>Plazo (meses)</label>
          <select id="hs-months">
            <?php foreach ([6,12,18,24,36,48] as $m): ?>
              <option value="<?= $m ?>" <?= $m === 12 ? 'selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <label>Producto</label>
      <select id="hs-rate">
        <option value="36" data-fee="1.5"><i class="fa-solid fa-seedling"></i> Solidaria · TNA 36%</option>
        <option value="48" data-fee="2.5" selected><i class="fa-solid fa-user"></i> Personal · TNA 48%</option>
        <option value="65" data-fee="3"><i class="fa-solid fa-bolt"></i> Rápido · TNA 65%</option>
        <option value="68" data-fee="2.5"><i class="fa-solid fa-building"></i> PyME · TNA 68%</option>
      </select>
      <button class="btn btn-accent" type="button" id="hs-run" style="margin-top:1rem">
        <i class="fa-solid fa-calculator"></i> Calcular ahora
      </button>
    </form>
    <div class="mini-sim-result" id="hs-out">
      <p class="muted"><i class="fa-solid fa-sliders" style="margin-right:.35rem"></i>Elegí monto y plazo. Vas a ver exactamente lo que vas a pagar, sin sorpresas.</p>
    </div>
  </div>
</section>

<section class="section" data-reveal>
  <div class="section-head center">
    <p class="section-eyebrow"><i class="fa-solid fa-star"></i> Por qué elegirnos</p>
    <h2 class="section-title">Una cuenta para todo el <em>crédito</em> y la <em>inversión</em>.</h2>
    <p class="section-lead">No es un simple formulario web. Es un sistema financiero completo: identidad verificada, dinero real, cuotas, mercado P2P y portafolio — todo integrado.</p>
  </div>
  <div class="feature-grid why-grid">
    <article class="feature feature-gold">
      <div class="feature-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>
      <h3>Desembolso del 100%</h3>
      <p>Pedís $500.000 y recibís $500.000. La comisión viaja en las cuotas, nunca se descuenta del capital que cobrás.</p>
    </article>
    <article class="feature">
      <div class="feature-icon"><i class="fa-solid fa-piggy-bank"></i></div>
      <h3>Billetera real CVU + Alias</h3>
      <p>Subcuenta bancaria, alias, CVU, QR y recargas con Mercado Pago. Tu saldo Credimax es operativo, no ficticio.</p>
    </article>
    <article class="feature feature-alt">
      <div class="feature-icon"><i class="fa-solid fa-percent"></i></div>
      <h3>CFT comparable</h3>
      <p>TNA, TEA, CFT TNA y CFT TEA con IVA. En el simulador, en el marketplace y en cada ficha de crédito aprobado.</p>
    </article>
    <article class="feature">
      <div class="feature-icon"><i class="fa-solid fa-chart-mixed"></i></div>
      <h3>Inversor al mando</h3>
      <p>Perfiles AA–F. Fondeo manual. Sin auto-colocación obligatoria. Totalmente alineado al modelo PSCPP argentino.</p>
    </article>
  </div>
  <div class="actions" style="margin-top:1.8rem;justify-content:center;display:flex">
    <a class="btn btn-accent" href="<?= e(url('/por-que-credimax')) ?>"><i class="fa-solid fa-book-open"></i> Por qué Credimax</a>
    <a class="btn" href="<?= e(url('/tasas')) ?>"><i class="fa-solid fa-table"></i> Tabla de tasas</a>
  </div>
</section>

<section class="section section-alt" data-reveal>
  <div class="section-head center">
    <p class="section-eyebrow"><i class="fa-solid fa-route"></i> En 4 pasos</p>
    <h2 class="section-title">Cómo <em>operar</em> en Credimax</h2>
    <p class="section-lead">Tanto si necesitás un préstamo como si querés invertir, el flujo es simple, rápido y transparente.</p>
  </div>
  <div class="steps-wrap">
    <article class="step-card">
      <div class="step-num">1</div>
      <i class="fa-solid fa-user-plus step-icon"></i>
      <h3>Abrí tu cuenta</h3>
      <p>Registro, DNI, verificación facial y billetera lista en menos de 10 minutos.</p>
    </article>
    <article class="step-card">
      <div class="step-num">2</div>
      <i class="fa-solid fa-building-columns step-icon"></i>
      <h3>Cargá saldo</h3>
      <p>Mercado Pago, transferencia bancaria o CVU — tesorería concilia y acredita.</p>
    </article>
    <article class="step-card">
      <div class="step-num">3</div>
      <i class="fa-solid fa-scale-balanced step-icon"></i>
      <h3>Pedí o invertí</h3>
      <p>Marketplace abierto, filtros de riesgo, TNA y plazo. Decidís vos, operación por operación.</p>
    </article>
    <article class="step-card">
      <div class="step-num">4</div>
      <i class="fa-solid fa-chart-line step-icon"></i>
      <h3>Gestioná</h3>
      <p>Cuotas, cobros, retiros, estado de cuenta y portafolio, desde la misma app.</p>
    </article>
  </div>
</section>

<section class="section" data-reveal>
  <div class="split-grid">
    <div class="split-visual">
      <img src="<?= e($splitBorrow) ?>" alt="Cliente recibiendo su préstamo" loading="lazy" />
    </div>
    <div>
      <p class="section-eyebrow"><i class="fa-solid fa-hand-holding-heart"></i> Para quienes necesitan plata</p>
      <h2 class="section-title" style="margin:.35rem 0 .8rem">Un préstamo claro, no <em>un atajo</em>.</h2>
      <p class="section-lead">Hasta $5.000.000 · hasta 48 cuotas · personas y PyME · sistema francés.</p>
      <ul class="split-list">
        <li><i class="fa-solid fa-check"></i> Simulá cuota y CFT <strong>antes</strong> de postular</li>
        <li><i class="fa-solid fa-check"></i> Recibís el monto completo en tu billetera, sin descuento previo</li>
        <li><i class="fa-solid fa-check"></i> Pagás cuota por cuota, débito automático o manual</li>
        <li><i class="fa-solid fa-check"></i> KYC + scoring transparente, sin consulta oculta</li>
      </ul>
      <div class="actions" style="margin-top:1.3rem">
        <a class="btn btn-accent" href="<?= e(url('/pedir-credito')) ?>"><i class="fa-solid fa-bullseye"></i> Quiero un préstamo</a>
        <a class="btn" href="<?= e(url('/requisitos')) ?>"><i class="fa-solid fa-clipboard-list"></i> Ver requisitos</a>
      </div>
    </div>
  </div>
</section>

<section class="section section-alt" data-reveal>
  <div class="split-grid" style="grid-auto-flow:dense">
    <div>
      <p class="section-eyebrow"><i class="fa-solid fa-sack-dollar"></i> Para quienes invierten</p>
      <h2 class="section-title" style="margin:.35rem 0 .8rem">Rendimiento con <em>criterio propio</em>.</h2>
      <p class="section-lead">Retorno estimado por perfil. Riesgo explícito. Vos elegís cada crédito, sin auto-colocación forzada.</p>
      <ul class="split-list">
        <li><i class="fa-solid fa-check"></i> Entrada desde $5.000 ARS por operación</li>
        <li><i class="fa-solid fa-check"></i> Marketplace con TNA, plazo, banda de riesgo y scoring</li>
        <li><i class="fa-solid fa-check"></i> Cobro prorrateado, cuota a cuota, con detalle completo</li>
        <li><i class="fa-solid fa-check"></i> Export de rentas anual para AFIP, listo para presentación</li>
      </ul>
      <div class="actions" style="margin-top:1.3rem">
        <a class="btn btn-accent" href="<?= e(url('/invertir')) ?>"><i class="fa-solid fa-chart-line"></i> Quiero invertir</a>
        <a class="btn" href="<?= e(url('/marketplace')) ?>"><i class="fa-solid fa-store"></i> Ver el mercado</a>
      </div>
    </div>
    <div class="split-visual">
      <img src="<?= e($splitInvest) ?>" alt="Inversor revisando su portafolio" loading="lazy" />
    </div>
  </div>
</section>

<section class="section" data-reveal>
  <div class="section-head center">
    <p class="section-eyebrow"><i class="fa-solid fa-comments"></i> Opiniones</p>
    <h2 class="section-title">Lo que dicen nuestros <em>usuarios</em></h2>
    <p class="section-lead">Casos reales de familias y PyMEs argentinas usando Credimax en el día a día.</p>
  </div>
  <div class="testimonials">
    <article class="testimonial">
      <i class="fa-solid fa-quote-left"></i>
      <div class="testimonial-stars" aria-label="5 estrellas">★★★★★</div>
      <p>Pedí un préstamo personal para arreglar el auto. Me aprobaron en 3 horas y el dinero estaba en mi CVU. El CFT coincidía tal cual el simulador. 10/10.</p>
      <div class="testimonial-person">
        <div class="testimonial-avatar">LR</div>
        <div>
          <strong>Lucía Rodríguez</strong>
          <span>Docente · Córdoba · Prestataria</span>
        </div>
      </div>
    </article>
    <article class="testimonial">
      <i class="fa-solid fa-quote-left"></i>
      <div class="testimonial-stars" aria-label="5 estrellas">★★★★★</div>
      <p>Como PyME necesitábamos capital de giro. Tasa clara, plazo cómodo y todo por app. Nos salvó la temporada alta sin meternos con un banco.</p>
      <div class="testimonial-person">
        <div class="testimonial-avatar">MG</div>
        <div>
          <strong>Matías Gómez</strong>
          <span>Comercio minorista · CABA · PyME</span>
        </div>
      </div>
    </article>
    <article class="testimonial">
      <i class="fa-solid fa-quote-left"></i>
      <div class="testimonial-stars" aria-label="5 estrellas">★★★★★</div>
      <p>Armé una cartera de 28 operaciones con perfiles B y C. La tasa ronda el 48% anual y los cobros son puntuales. Export para AFIP listo en un clic. Feliz.</p>
      <div class="testimonial-person">
        <div class="testimonial-avatar">CS</div>
        <div>
          <strong>Carla Suárez</strong>
          <span>Inversora particular · Santa Fe</span>
        </div>
      </div>
    </article>
  </div>
</section>

<section class="section section-alt" data-reveal>
  <div class="section-head center">
    <p class="section-eyebrow"><i class="fa-solid fa-circle-question"></i> FAQ</p>
    <h2 class="section-title">Preguntas <em>frecuentes</em></h2>
    <p class="section-lead">Las dudas más comunes sobre Credimax. Si no encontrás la tuya, escribinos a soporte.</p>
  </div>
  <div class="faq-premium">
    <details>
      <summary><span><i class="fa-solid fa-circle-question"></i> ¿Es seguro guardar mi dinero en Credimax?</span><i class="fa-solid fa-chevron-down chev"></i></summary>
      <p>Operamos como plataforma tecnológica de créditos P2P bajo el modelo PSCPP. Tu dinero se aloja en una cuenta bancaria segregada y cada movimiento se registra con ledger contable. Tus datos KYC están encriptados AES-256-GCM en reposo.</p>
    </details>
    <details>
      <summary><span><i class="fa-solid fa-circle-question"></i> ¿Qué costos y comisiones tiene un préstamo?</span><i class="fa-solid fa-chevron-down chev"></i></summary>
      <p>Comisión de otorgamiento, IVA, gastos administrativos e intereses según la TNA del producto. Todos los valores — TNA, TEA, CFT TNA y CFT TEA — se muestran de forma explícita en el simulador y antes de aceptar la oferta.</p>
    </details>
    <details>
      <summary><span><i class="fa-solid fa-circle-question"></i> ¿Puedo invertir poco a poco?</span><i class="fa-solid fa-chevron-down chev"></i></summary>
      <p>Sí. El monto mínimo por operación en el marketplace es de $5.000 ARS. Podés armar tu propia cartera distribuyendo riesgo, y cada cuota cobrada se acredita automáticamente en tu billetera.</p>
    </details>
    <details>
      <summary><span><i class="fa-solid fa-circle-question"></i> ¿Qué pasa si un prestatario no paga?</span><i class="fa-solid fa-chevron-down chev"></i></summary>
      <p>Existe una marcación diaria de mora, intereses punitorios (3×TNA anual diario) y gastos de cobranza. La plataforma notifica y gestiona cobranzas, pero el riesgo crediticio es asumido por cada inversor según el modelo PSCPP.</p>
    </details>
    <details>
      <summary><span><i class="fa-solid fa-circle-question"></i> ¿En cuánto retiro mi dinero?</span><i class="fa-solid fa-chevron-down chev"></i></summary>
      <p>Solicitás el retiro desde la billetera con CVU o alias. Tesorería confirma y envía la transferencia bancaria dentro de las 24 hábiles. Para montos altos aplica doble aprobación y 2FA obligatorio.</p>
    </details>
  </div>
  <div class="actions" style="margin-top:1.3rem;justify-content:center;display:flex">
    <a class="btn" href="<?= e(url('/faq')) ?>"><i class="fa-solid fa-list-ul"></i> Ver todas las preguntas</a>
    <a class="btn btn-accent" href="<?= e(url('/contacto')) ?>"><i class="fa-solid fa-paper-plane"></i> Contacto</a>
  </div>
</section>

<section class="section" data-reveal>
  <div class="final-cta">
    <div class="final-cta-inner">
      <h2>Argentina elige <em>claridad</em>. Tu cuenta <?= e($brand) ?>, a un clic.</h2>
      <p>Abrí tu cuenta gratis, verificá identidad y operá en pesos. Confianza, velocidad y costos que se entienden desde el primer día.</p>
      <div class="actions">
        <a class="btn btn-accent btn-lg" href="<?= e(url('/register')) ?>"><i class="fa-solid fa-rocket"></i> Abrir cuenta gratis</a>
        <a class="btn btn-lg" href="<?= e(url('/simulador')) ?>"><i class="fa-solid fa-calculator"></i> Simular ahora</a>
      </div>
      <div class="final-cta-meta">
        <span><i class="fa-solid fa-circle-check"></i> Sin costo de apertura</span>
        <span><i class="fa-solid fa-shield-halved"></i> KYC y 2FA incluidos</span>
        <span><i class="fa-solid fa-headset"></i> Soporte humano en Argentina</span>
        <span><i class="fa-solid fa-file-invoice-dollar"></i> Costos 100% transparentes</span>
      </div>
    </div>
  </div>
</section>

<script nonce="<?= e($nonce) ?>">
(function(){
  var slides = document.querySelectorAll('.hero-slide');
  var dots   = document.querySelectorAll('.hero-dots button');
  var idx = 0;
  function go(i){
    idx = (i + slides.length) % slides.length;
    slides.forEach(function(s,k){ s.classList.toggle('is-active', k===idx); });
    dots.forEach(function(b,k){   b.classList.toggle('is-active', k===idx); });
  }
  setInterval(function(){ go(idx+1); }, 6000);
  dots.forEach(function(b){ b.addEventListener('click', function(){ go(parseInt(b.getAttribute('data-hero-idx'), 10)); }); });

  const base = <?= json_encode(url('/api/simulator')) ?>;
  const amount = document.getElementById('hs-amount');
  const months = document.getElementById('hs-months');
  const rate = document.getElementById('hs-rate');
  const out = document.getElementById('hs-out');
  function money(n){ return Number(n).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  async function run(){
    const o = rate.options[rate.selectedIndex];
    const url = base + '?amount=' + encodeURIComponent(amount.value)
      + '&months=' + encodeURIComponent(months.value)
      + '&rate=' + encodeURIComponent(rate.value)
      + '&fee=' + encodeURIComponent(o.dataset.fee || '2.5');
    try {
      const res = await fetch(url); const data = await res.json();
      if (!data || !data.ok) { out.innerHTML = '<p class="muted">No se pudo calcular, revisá los valores.</p>'; return; }
      out.innerHTML = '<ul class="kv">'
        + '<li><span>Vas a recibir</span><strong>$ ' + money(data.disbursement) + '</strong></li>'
        + '<li><span>Cuota mensual</span><strong>$ ' + money(data.installment) + '</strong></li>'
        + '<li><span>TNA / TEA</span><strong>' + data.tna + '% / ' + data.tea + '%</strong></li>'
        + '<li class="rate-highlight"><span>CFT TEA (costo total)</span><strong>' + data.cft_tea + '%</strong></li>'
        + '</ul><div class="actions" style="margin-top:1rem"><a class="btn btn-accent" href="<?= e(url('/register')) ?>"><i class="fa-solid fa-user-plus"></i> Continuar con estos números</a></div>';
    } catch(e){
      out.innerHTML = '<p class="muted">Revisá tu conexión e intentá nuevamente.</p>';
    }
  }
  document.getElementById('hs-run')?.addEventListener('click', run);
})();
</script>
