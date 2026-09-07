<?php

declare(strict_types=1);

namespace App\Domain\Verification\Exports;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR code as inline SVG, drawn from the encoder's raw matrix.
 *
 * BaconQrCode ships SVG and PNG writers, and both are wrong here. The PNG
 * writer wants imagick or gd; the bundled SVG writer emits its own document
 * with its own dimensions and a namespace declaration, which is fine standalone
 * and awkward inside a page that already has a stylesheet and a print layout.
 *
 * What the certificate needs is a `<path>` it can size in millimetres like any
 * other element, so this walks the matrix and emits one. Rendering is a single
 * path of rectangles rather than one element per module, because a 33 by 33
 * code is a thousand modules and a thousand elements is a slow print.
 *
 * Error correction is deliberately high. This is printed, handled, folded and
 * photographed off a counter in poor light, and the whole document is worthless
 * if the code will not scan.
 */
final class QrCode
{
    /**
     * @param  int  $size  Rendered width in user units. The viewBox is the
     *                     module count, so the caller sizes it with CSS and
     *                     this stays crisp at any scale.
     */
    public function svg(string $text, int $size = 160): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::H())->getMatrix();

        $width = $matrix->getWidth();
        $height = $matrix->getHeight();

        // A quiet zone is part of the specification, not decoration: a scanner
        // needs the clear border to find the symbol at all.
        $quiet = 2;
        $extent = $width + ($quiet * 2);

        $path = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= sprintf('M%d %dh1v1h-1z', $x + $quiet, $y + $quiet);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" '
            .'shape-rendering="crispEdges" role="img" aria-label="Verification code">'
            .'<rect width="%d" height="%d" fill="#ffffff"/>'
            .'<path d="%s" fill="#16202B"/>'
            .'</svg>',
            $extent,
            $extent,
            $size,
            $size,
            $extent,
            $extent,
            $path,
        );
    }
}
