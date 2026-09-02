<?php
declare(strict_types=1);
/** Genera una página que decodifica el QR con jsQR (lector real). Temporal. CLI only. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo por CLI.\n";
    exit(1);
}

require __DIR__ . '/../app/bootstrap.php';

$cases = [
    'checkout_mp' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=1234567890-abcdef01-2345-6789-abcd-ef0123456789',
    'cobro_corto' => 'https://credimax.com.ar/cobro/CMX-CHARGE-42',
    'payload_wallet' => '{"v":1,"app":"credimax","id":"CMX-4F2A9B1C","cvu":"9000001000000000123456","alias":"credimax.a1b2c3"}',
];

$blocks = '';
foreach ($cases as $name => $text) {
    $svg = App\Helpers\QrSvg::render($text, 6, 4);
    $blocks .= '<section data-name="' . $name . '" data-expected="' . htmlspecialchars($text, ENT_QUOTES) . '">'
        . '<h3>' . $name . '</h3><div class="qr">' . $svg . '</div><pre class="out">pendiente</pre></section>';
}

$html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>Verificación QR Credimax</title>
<style>body{font-family:system-ui;background:#fff;padding:20px} section{margin-bottom:24px} pre{font-size:13px}</style>
<body>
<h1>Verificación de QR con lector real (jsQR)</h1>
{$blocks}
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
(async () => {
  for (const section of document.querySelectorAll('section')) {
    const expected = section.dataset.expected;
    const svgEl = section.querySelector('svg');
    const serialized = new XMLSerializer().serializeToString(svgEl);
    const url = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(serialized)));
    const img = new Image();
    await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = img.width; canvas.height = img.height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0);
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const result = jsQR(data.data, canvas.width, canvas.height);
    const out = section.querySelector('.out');
    if (!result) { out.textContent = 'DECODE_FAIL'; }
    else if (result.data === expected) { out.textContent = 'DECODE_OK :: ' + result.data; }
    else { out.textContent = 'DECODE_MISMATCH :: ' + result.data; }
  }
  document.title = 'LISTO ' + [...document.querySelectorAll('.out')].map(o => o.textContent.split(' ')[0]).join('|');
})();
</script>
HTML;

file_put_contents(__DIR__ . '/qrtest.html', $html);
echo "OK: storage/qrtest.html\n";
