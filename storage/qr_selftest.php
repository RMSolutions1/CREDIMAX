<?php
declare(strict_types=1);
/** Verificación del codificador QR contra vectores conocidos. Temporal. CLI only. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo por CLI.\n";
    exit(1);
}

require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\QrCode;

$ok = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name  $detail\n";
    }
}

$ref = new ReflectionClass(QrCode::class);
$call = function (string $method, array $args) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};

// 1) Cadenas de información de formato para nivel M (tabla del estándar)
$expectedFormat = [
    0 => '101010000010010',
    1 => '101000100100101',
    2 => '101111001111100',
    3 => '101101101001011',
    4 => '100010111111001',
    5 => '100000011001110',
    6 => '100111110010111',
    7 => '100101010100000',
];
foreach ($expectedFormat as $mask => $expected) {
    $bits = $call('formatInfoBits', [$mask]);
    $got = str_pad(decbin($bits), 15, '0', STR_PAD_LEFT);
    check("formato mascara $mask", $got === $expected, "esperado $expected, obtenido $got");
}

// 2) Información de versión (tabla del estándar)
$expectedVersion = [
    7 => '000111110010010100',
    10 => '001010010011010011',
    14 => '001110011000001101',
    20 => '010100100110100110',
];
foreach ($expectedVersion as $v => $expected) {
    $bits = $call('versionInfoBits', [$v]);
    $got = str_pad(decbin($bits), 18, '0', STR_PAD_LEFT);
    check("version $v", $got === $expected, "esperado $expected, obtenido $got");
}

// 3) Reed-Solomon: vector clásico de "HELLO WORLD" (10 codewords ECC)
$data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
$expectedEcc = [196, 35, 39, 119, 235, 215, 231, 226, 93, 23];
$gotEcc = $call('reedSolomon', [$data, 10]);
check('reed-solomon', $gotEcc === $expectedEcc, 'obtenido ' . implode(',', $gotEcc));

// 4) Estructura de la matriz
foreach ([['https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123456789-abcd', 0], ['CMX', 0]] as [$text, $_]) {
    $m = QrCode::matrix($text);
    $size = count($m);
    check('matriz cuadrada (' . $size . ')', $size === count($m[0]) && ($size - 21) % 4 === 0);

    // Patrones de localización en las tres esquinas
    $finderOk = true;
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $expected = ($r === 0 || $r === 6 || $c === 0 || $c === 6)
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $finderOk = $finderOk && $m[$r0 + $r][$c0 + $c] === $expected;
            }
        }
    }
    check('patrones de localizacion', $finderOk);

    $timingOk = true;
    for ($i = 8; $i < $size - 8; $i++) {
        $timingOk = $timingOk && $m[6][$i] === ($i % 2 === 0) && $m[$i][6] === ($i % 2 === 0);
    }
    check('patrones de sincronismo', $timingOk);
    check('modulo oscuro', $m[$size - 8][8] === true);
}

// 5) Ida y vuelta: leer la matriz con el mismo recorrido y recuperar el texto
$text = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=1234567890-abcdef01-2345';
$m = QrCode::matrix($text);
$size = count($m);
$version = (int) (($size - 21) / 4) + 1;

// Recuperar la máscara desde la información de formato
$formatBits = '';
for ($i = 14; $i >= 0; $i--) {
    if ($i < 6) {
        $formatBits .= $m[$i][8] ? '1' : '0';
    } elseif ($i === 6) {
        $formatBits .= $m[7][8] ? '1' : '0';
    } elseif ($i === 7) {
        $formatBits .= $m[8][8] ? '1' : '0';
    } elseif ($i === 8) {
        $formatBits .= $m[8][7] ? '1' : '0';
    } else {
        $formatBits .= $m[8][14 - $i] ? '1' : '0';
    }
}
// Los 5 bits de datos viven en las posiciones altas: (nivel ECC << 3 | mascara) << 10
$formatValue = bindec($formatBits) ^ 0x5412;
$mask = ($formatValue >> 10) & 0b111;
$eccLevelBits = ($formatValue >> 13) & 0b11;
check('nivel ECC leido = M', $eccLevelBits === 0b00, 'obtenido ' . $eccLevelBits);

$reserved = $call('reservedMap', [$size, $version]);
$unmasked = $call('applyMask', [$m, $reserved, $size, $mask]);

$bits = '';
$upward = true;
for ($col = $size - 1; $col > 0; $col -= 2) {
    if ($col === 6) {
        $col--;
    }
    for ($i = 0; $i < $size; $i++) {
        $row = $upward ? $size - 1 - $i : $i;
        foreach ([$col, $col - 1] as $c) {
            if ($reserved[$row][$c]) {
                continue;
            }
            $bits .= $unmasked[$row][$c] ? '1' : '0';
        }
    }
    $upward = !$upward;
}

// De-intercalado de los codewords de datos
[$eccPerBlock, $blocks1, $data1, $blocks2, $data2] = (new ReflectionClass(QrCode::class))
    ->getConstant('ECC_M')[$version];
$stream = [];
foreach (str_split(substr($bits, 0, (int) (strlen($bits) / 8) * 8), 8) as $b) {
    $stream[] = bindec($b);
}

$blockSizes = array_merge(array_fill(0, $blocks1, $data1), array_fill(0, $blocks2, $data2));
$blocks = array_fill(0, count($blockSizes), []);
$idx = 0;
for ($i = 0; $i < max($data1, $data2); $i++) {
    foreach ($blockSizes as $b => $len) {
        if ($i < $len) {
            $blocks[$b][] = $stream[$idx++];
        }
    }
}
$dataCodewords = array_merge(...$blocks);

$bitStream = '';
foreach ($dataCodewords as $cw) {
    $bitStream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
}
$mode = substr($bitStream, 0, 4);
$countBits = $version <= 9 ? 8 : 16;
$length = bindec(substr($bitStream, 4, $countBits));
$decoded = '';
for ($i = 0; $i < $length; $i++) {
    $decoded .= chr(bindec(substr($bitStream, 4 + $countBits + $i * 8, 8)));
}

check('modo byte', $mode === '0100', "obtenido $mode");
check('longitud', $length === strlen($text), "esperado " . strlen($text) . ", obtenido $length");
check('texto recuperado', $decoded === $text, "obtenido: $decoded");

echo "\n--- $ok correctas, $fail fallidas ---\n";
exit($fail === 0 ? 0 : 1);
