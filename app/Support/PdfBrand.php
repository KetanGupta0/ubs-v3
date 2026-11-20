<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The company logo, as every PDF shows it.
 *
 * dompdf has neither the brand's typeface nor reliable SVG gradients, so the
 * lockup BrandLogo draws on screen is a PNG here (public/brand/logo-pdf.png,
 * made by resources/brand/render-pdf-logo.cjs), and it reaches dompdf as data:
 * a document renderer needs no file or network access to draw it. On a dark
 * ground the lockup is white (logo-pdf-light.png), as BrandLogo's monochrome
 * variant draws it.
 */
class PdfBrand
{
    public const LOGO = 'brand/logo-pdf.png';

    public const LOGO_LIGHT = 'brand/logo-pdf-light.png';

    /** @var array<string, array{0: string, 1: float}> The data URI, and width over height, by file. */
    protected static array $logos = [];

    /** The logo this many millimetres tall, at its own proportions; nothing if the file is missing. */
    public static function img(float $height, string $style = '', bool $light = false): HtmlString
    {
        [$uri, $ratio] = self::logo($light ? self::LOGO_LIGHT : self::LOGO);

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
    protected static function logo(string $file): array
    {
        if (isset(self::$logos[$file])) {
            return self::$logos[$file];
        }

        $path = public_path($file);
        $size = is_file($path) ? getimagesize($path) : false;

        return self::$logos[$file] = $size
            ? ['data:image/png;base64,'.base64_encode((string) file_get_contents($path)), $size[0] / max(1, $size[1])]
            : ['', 1.0];
    }
}
