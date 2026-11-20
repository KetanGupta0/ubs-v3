<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * What the premium certificate layouts are drawn with besides text: gold
 * ornaments and typefaces.
 *
 * The ornaments are PNGs made by resources/certificates/render-art.cjs and
 * reach dompdf as data, like the logo. The typefaces (Cinzel, Cormorant
 * Garamond, Playfair Display and Great Vibes, all under the SIL Open Font
 * Licence, in resources/fonts/certificates) are local files inside dompdf's
 * chroot; dompdf reads each one the first time it is used and keeps its
 * metrics in storage/fonts.
 */
class CertificateArt
{
    /** Family => the files for its normal and italic styles. */
    public const FONTS = [
        'cinzel' => ['normal' => 'Cinzel-SemiBold.ttf'],
        'cormorant' => ['normal' => 'CormorantGaramond-Medium.ttf', 'italic' => 'CormorantGaramond-MediumItalic.ttf'],
        'playfair' => ['normal' => 'PlayfairDisplay-Bold.ttf', 'italic' => 'PlayfairDisplay-Italic.ttf'],
        'greatvibes' => ['normal' => 'GreatVibes-Regular.ttf'],
    ];

    /** @var array<string, string> */
    protected static array $images = [];

    /** An ornament as a data URI, or an empty string if its file is missing. */
    public static function uri(string $name): string
    {
        if (! isset(self::$images[$name])) {
            $path = resource_path('certificates/art/'.basename($name).'.png');
            self::$images[$name] = is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : '';
        }

        return self::$images[$name];
    }

    /** The @font-face rules for the families a layout uses. */
    public static function fontFaces(string ...$families): HtmlString
    {
        $rules = [];

        foreach ($families as $family) {
            foreach (self::FONTS[$family] ?? [] as $style => $file) {
                $rules[] = sprintf(
                    "@font-face { font-family: '%s'; font-style: %s; font-weight: normal; src: url('%s') format('truetype'); }",
                    $family,
                    $style,
                    resource_path('fonts/certificates/'.$file),
                );
            }
        }

        return new HtmlString(implode("\n", $rules));
    }

    /**
     * The design's accent where a layout prints it: the accent itself if it
     * makes at least 4.5:1 (WCAG AA) with every colour it meets there, words
     * on it or it as words on them, else the layout's own colour.
     */
    public static function ink(?string $accent, string $fallback, string ...$against): string
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent)) {
            return $fallback;
        }

        foreach ($against ?: ['#ffffff'] as $other) {
            if (self::contrast((string) $accent, $other) < 4.5) {
                return $fallback;
            }
        }

        return strtolower((string) $accent);
    }

    /** The WCAG contrast ratio of two colours written #rrggbb, from 1 to 21. */
    public static function contrast(string $one, string $two): float
    {
        [$a, $b] = [self::luminance($one), self::luminance($two)];

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    protected static function luminance(string $hex): float
    {
        $luminance = 0.0;

        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $channel = hexdec(substr($hex, 1 + $i * 2, 2)) / 255;
            $luminance += $weight * ($channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4);
        }

        return $luminance;
    }

    /**
     * A size in pixels that keeps a line of text on one line as it gets
     * longer: the full size up to `$fits` characters, then smaller in
     * proportion, never below `$floor`.
     */
    public static function fit(string $text, int $fits, float $size, float $floor): float
    {
        $length = max(1, mb_strlen(trim($text)));

        return round(max($floor, $length <= $fits ? $size : $size * $fits / $length), 1);
    }
}
