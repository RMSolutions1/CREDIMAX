<?php
declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Codificador de QR Code real (ISO/IEC 18004), sin dependencias ni servicios externos.
 *
 * Modo byte, nivel de corrección M, versiones 1 a 20 (hasta ~660 bytes), lo que cubre
 * de sobra las URLs de checkout de Mercado Pago y los payloads de la billetera.
 * Devuelve la matriz de módulos; el render a SVG lo hace QrSvg.
 */
final class QrCode
{
    /** [codewords ECC por bloque, bloques grupo 1, datos grupo 1, bloques grupo 2, datos grupo 2] — nivel M */
    private const ECC_M = [
        1 => [10, 1, 16, 0, 0],
        2 => [16, 1, 28, 0, 0],
        3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0],
        8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
        11 => [30, 1, 50, 4, 51],
        12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38],
        14 => [24, 4, 40, 5, 41],
        15 => [24, 5, 41, 5, 42],
        16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47],
        18 => [26, 9, 43, 4, 44],
        19 => [26, 3, 44, 11, 45],
        20 => [26, 3, 41, 13, 42],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** Bits de relleno al final del flujo, por versión. */
    private const REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
        7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0, 13 => 0,
        14 => 3, 15 => 3, 16 => 3, 17 => 3, 18 => 3, 19 => 3, 20 => 3,
    ];

    private const PAD_BYTES = [0xEC, 0x11];

    /** @var array<int,int> */
    private static array $expTable = [];
    /** @var array<int,int> */
    private static array $logTable = [];

    /**
     * Genera la matriz de módulos del QR.
     * @return array<int,array<int,bool>> matriz[fila][columna]
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::pickVersion(count($bytes));
        $dataCodewords = self::buildDataCodewords($bytes, $version);
        $finalCodewords = self::interleave($dataCodewords, $version);

        $size = 21 + 4 * ($version - 1);
        $reserved = self::reservedMap($size, $version);
        $modules = self::blankModules($size, $version);

        self::placeData($modules, $reserved, $finalCodewords, $size, $version);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($modules, $reserved, $size, $mask);
            self::placeFormatInfo($candidate, $size, $mask);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new RuntimeException('No se pudo generar el código QR.');
        }
        return $best;
    }

    // -------------------------------------------------------- Codificación

    private static function pickVersion(int $byteCount): int
    {
        foreach (array_keys(self::ECC_M) as $version) {
            $countBits = $version <= 9 ? 8 : 16;
            $capacityBits = self::dataCodewordCount($version) * 8;
            if (4 + $countBits + $byteCount * 8 <= $capacityBits) {
                return $version;
            }
        }
        throw new RuntimeException('El contenido es demasiado largo para un QR (máx. ~660 bytes).');
    }

    private static function dataCodewordCount(int $version): int
    {
        [, $blocks1, $data1, $blocks2, $data2] = self::ECC_M[$version];
        return $blocks1 * $data1 + $blocks2 * $data2;
    }

    /**
     * @param list<int> $bytes
     * @return list<int> codewords de datos
     */
    private static function buildDataCodewords(array $bytes, int $version): array
    {
        $capacity = self::dataCodewordCount($version);
        $countBits = $version <= 9 ? 8 : 16;

        $bits = '0100';                                                   // modo byte
        $bits .= str_pad(decbin(count($bytes)), $countBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        // Terminador de hasta 4 bits y relleno hasta byte completo.
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        $codewords = [];
        foreach (str_split($bits, 8) as $chunk) {
            $codewords[] = bindec($chunk);
        }
        $i = 0;
        while (count($codewords) < $capacity) {
            $codewords[] = self::PAD_BYTES[$i % 2];
            $i++;
        }

        return $codewords;
    }

    /**
     * Divide en bloques, calcula ECC e intercala según la norma.
     * @param list<int> $dataCodewords
     * @return list<int>
     */
    private static function interleave(array $dataCodewords, int $version): array
    {
        [$eccPerBlock, $blocks1, $data1, $blocks2, $data2] = self::ECC_M[$version];

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        foreach ([[$blocks1, $data1], [$blocks2, $data2]] as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($dataCodewords, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $eccBlocks[] = self::reedSolomon($block, $eccPerBlock);
            }
        }

        $out = [];
        $maxData = max($data1, $data2);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        return $out;
    }

    // -------------------------------------------------- Reed-Solomon GF(256)

    private static function initTables(): void
    {
        if (self::$expTable !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // polinomio primitivo
            }
        }
        for ($i = 256; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        self::initTables();

        // Polinomio generador: producto de (x - a^i)
        $generator = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coeff) {
                $next[$j] ^= $coeff;                                        // término en x
                $next[$j + 1] ^= self::gfMul($coeff, self::$expTable[$i]);  // término en a^i
            }
            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $eccCount, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $j => $coeff) {
                $remainder[$i + $j] ^= self::gfMul($coeff, $factor);
            }
        }

        return array_slice($remainder, count($data), $eccCount);
    }

    // ------------------------------------------------------------ Matriz

    /** @return array<int,array<int,bool>> */
    private static function blankModules(int $size, int $version): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, false));

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$row, $col]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $edge = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                    $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $m[$row + $r][$col + $c] = $edge || $core;
                }
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            $m[6][$i] = $i % 2 === 0;
            $m[$i][6] = $i % 2 === 0;
        }

        foreach (self::alignmentCenters($version) as [$row, $col]) {
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $m[$row + $r][$col + $c] = max(abs($r), abs($c)) !== 1;
                }
            }
        }

        $m[$size - 8][8] = true; // módulo oscuro obligatorio

        if ($version >= 7) {
            $bits = self::versionInfoBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (bool) (($bits >> $i) & 1);
                $m[intdiv($i, 3)][$size - 11 + $i % 3] = $bit;
                $m[$size - 11 + $i % 3][intdiv($i, 3)] = $bit;
            }
        }

        return $m;
    }

    /** Módulos que no pueden llevar datos ni máscara. @return array<int,array<int,bool>> */
    private static function reservedMap(int $size, int $version): array
    {
        $r = array_fill(0, $size, array_fill(0, $size, false));

        foreach ([[0, 0], [$size - 8, 0], [0, $size - 8]] as [$row, $col]) {
            for ($i = 0; $i < 8; $i++) {
                for ($j = 0; $j < 8; $j++) {
                    if (isset($r[$row + $i][$col + $j])) {
                        $r[$row + $i][$col + $j] = true;
                    }
                }
            }
        }

        for ($i = 0; $i < $size; $i++) {
            $r[6][$i] = true;
            $r[$i][6] = true;
            $r[8][$i] = $i < 9 || $i >= $size - 8 ? true : $r[8][$i];
            $r[$i][8] = $i < 9 || $i >= $size - 8 ? true : $r[$i][8];
        }

        foreach (self::alignmentCenters($version) as [$row, $col]) {
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $r[$row + $i][$col + $j] = true;
                }
            }
        }

        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $r[$i][$size - 11 + $j] = true;
                    $r[$size - 11 + $j][$i] = true;
                }
            }
        }

        return $r;
    }

    /** @return list<array{0:int,1:int}> */
    private static function alignmentCenters(int $version): array
    {
        $positions = self::ALIGNMENT[$version] ?? [];
        $size = 21 + 4 * ($version - 1);
        $centers = [];
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                // Se omiten los que chocan con los patrones de localización.
                $topLeft = $row <= 8 && $col <= 8;
                $topRight = $row <= 8 && $col >= $size - 9;
                $bottomLeft = $row >= $size - 9 && $col <= 8;
                if ($topLeft || $topRight || $bottomLeft) {
                    continue;
                }
                $centers[] = [$row, $col];
            }
        }
        return $centers;
    }

    /**
     * Recorre la matriz en zigzag de dos columnas desde abajo a la derecha.
     * @param array<int,array<int,bool>> $modules
     * @param array<int,array<int,bool>> $reserved
     * @param list<int> $codewords
     */
    private static function placeData(array &$modules, array $reserved, array $codewords, int $size, int $version): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', self::REMAINDER_BITS[$version] ?? 0);

        $index = 0;
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // la columna 6 es el patrón de sincronismo vertical
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                foreach ([$col, $col - 1] as $c) {
                    if ($reserved[$row][$c]) {
                        continue;
                    }
                    $modules[$row][$c] = ($bits[$index] ?? '0') === '1';
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    /**
     * @param array<int,array<int,bool>> $modules
     * @param array<int,array<int,bool>> $reserved
     * @return array<int,array<int,bool>>
     */
    private static function applyMask(array $modules, array $reserved, int $size, int $mask): array
    {
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($reserved[$row][$col] || !self::maskCondition($mask, $row, $col)) {
                    continue;
                }
                $modules[$row][$col] = !$modules[$row][$col];
            }
        }
        return $modules;
    }

    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => false,
        };
    }

    /** @param array<int,array<int,bool>> $modules */
    private static function placeFormatInfo(array &$modules, int $size, int $mask): void
    {
        $bits = self::formatInfoBits($mask);

        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($bits >> $i) & 1);

            // Copia junto al patrón superior izquierdo
            if ($i < 6) {
                $modules[$i][8] = $bit;
            } elseif ($i === 6) {
                $modules[7][8] = $bit;
            } elseif ($i === 7) {
                $modules[8][8] = $bit;
            } elseif ($i === 8) {
                $modules[8][7] = $bit;
            } else {
                $modules[8][14 - $i] = $bit;
            }

            // Copia redundante repartida entre los otros dos patrones
            if ($i < 8) {
                $modules[8][$size - 1 - $i] = $bit;
            } else {
                $modules[$size - 15 + $i][8] = $bit;
            }
        }

        $modules[$size - 8][8] = true;
    }

    /** BCH(15,5): nivel M (bits 00) + máscara, con XOR 0x5412. */
    private static function formatInfoBits(int $mask): int
    {
        $data = (0b00 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = (($rem << 1) ^ ((($rem >> 9) & 1) * 0x537)) & 0x3FF;
        }
        return ((($data << 10) | $rem) ^ 0x5412) & 0x7FFF;
    }

    /** BCH(18,6) para versiones 7 en adelante. */
    private static function versionInfoBits(int $version): int
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = (($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25)) & 0xFFF;
        }
        return (($version << 12) | $rem) & 0x3FFFF;
    }

    // ---------------------------------------------------------- Penalización

    /** @param array<int,array<int,bool>> $m */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // Regla 1: series de 5 o más módulos del mismo color
        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $isRow) {
                $run = 1;
                for ($j = 1; $j < $size; $j++) {
                    $prev = $isRow ? $m[$i][$j - 1] : $m[$j - 1][$i];
                    $curr = $isRow ? $m[$i][$j] : $m[$j][$i];
                    if ($prev === $curr) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Regla 2: bloques de 2x2 del mismo color
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $v = $m[$row][$col];
                if ($v === $m[$row][$col + 1] && $v === $m[$row + 1][$col] && $v === $m[$row + 1][$col + 1]) {
                    $score += 3;
                }
            }
        }

        // Regla 3: patrón 1:1:3:1:1 que puede confundirse con un localizador
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j <= $size - 11; $j++) {
                foreach ($patterns as $pattern) {
                    $rowMatch = true;
                    $colMatch = true;
                    for ($k = 0; $k < 11; $k++) {
                        $rowMatch = $rowMatch && $m[$i][$j + $k] === $pattern[$k];
                        $colMatch = $colMatch && $m[$j + $k][$i] === $pattern[$k];
                    }
                    $score += ($rowMatch ? 40 : 0) + ($colMatch ? 40 : 0);
                }
            }
        }

        // Regla 4: desbalance entre módulos oscuros y claros
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                $dark += $cell ? 1 : 0;
            }
        }
        $ratio = (int) floor(abs($dark * 100 / ($size * $size) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }
}
