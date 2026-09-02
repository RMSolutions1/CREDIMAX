<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Render SVG de códigos QR reales (ver QrCode), 100% local y sin CDN.
 */
final class QrSvg
{
    public static function render(string $text, int $scale = 4, int $margin = 4): string
    {
        try {
            $matrix = QrCode::matrix($text);
        } catch (\Throwable $e) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" role="img" aria-label="QR no disponible">'
                . '<rect width="100%" height="100%" fill="#f5f5f5"/>'
                . '<text x="60" y="64" font-size="9" text-anchor="middle" fill="#a00">QR no disponible</text></svg>';
        }

        $n = count($matrix);
        $size = ($n + 2 * $margin) * $scale;

        // Un solo path para todos los módulos oscuros: SVG mucho más liviano.
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix[$y][$x]) {
                    $path .= 'M' . (($x + $margin) * $scale) . ' ' . (($y + $margin) * $scale)
                        . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges" role="img" aria-label="Código QR">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/>'
            . '</svg>';
    }

    /** SVG embebible directamente en un atributo src. */
    public static function dataUri(string $text, int $scale = 4, int $margin = 4): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::render($text, $scale, $margin));
    }
}
