<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The company logo, as every PDF shows it.
 *
 * dompdf has neither the brand's typeface nor reliable SVG gradients, so the
 * lockup BrandLogo draws on screen is a PNG here (public/brand/logo-pdf.png,
 * made by resources/brand/render-pdf-logo.cjs), and it reaches dompdf as data:
 * a document renderer needs no file or network access to draw it.
 */
class PdfBrand
{
    public const LOGO = 'brand/logo-pdf.png';

    /** @var array{0: string, 1: float}|null The data URI, and width over height. */
    protected static ?array $logo = null;

    /** The logo this many millimetres tall, at its own proportions; nothing if the file is missing. */
    public static function img(float $height, string $style = ''): HtmlString
    {
        [$uri, $ratio] = self::logo();

        if ($uri === '') {
            return new HtmlString('');
        }

        return new HtmlString(sprintf(
            '<img src="%s" alt="%s" style="height: %smm; width: %smm;%s">',
            $uri,
            e(config('company.name')),
            $height,
            round($height * $ratio, 2),
            $style !== '' ? ' '.e($style) : '',
        ));
    }

    /** @return array{0: string, 1: float} */
    protected static function logo(): array
    {
        if (self::$logo !== null) {
            return self::$logo;
        }

        $path = public_path(self::LOGO);
        $size = is_file($path) ? getimagesize($path) : false;

        return self::$logo = $size
            ? ['data:image/png;base64,'.base64_encode((string) file_get_contents($path)), $size[0] / max(1, $size[1])]
            : ['', 1.0];
    }
}
