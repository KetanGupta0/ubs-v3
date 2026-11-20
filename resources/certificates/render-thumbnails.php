<?php

/*
 * The pictures of each certificate layout shown where a design picks one:
 * the preview's sample certificate, drawn by the real renderer, rasterised
 * with poppler's pdftoppm (a development tool, not needed in production) and
 * saved as WebP in public/images/certificate-layouts.
 *
 *     php resources/certificates/render-thumbnails.php
 *
 * Run it again after changing a layout.
 */

use App\Models\CertificateTemplate;
use App\Services\Lms\Credentials;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The wording each layout's design starts with (the migration that adds them).
$wording = [
    'classic' => ['Certificate of completion', 'has completed', '#4f46e5'],
    'regal' => ['Certificate of Completion', 'has successfully completed', '#1e2a4a'],
    'midnight' => ['Certificate of Achievement', 'has successfully completed', '#0e1630'],
    'heritage' => ['Certificate of Completion', 'is awarded this certificate for completing', '#6d1f2c'],
    'laurel' => ['Certificate of Achievement', 'has successfully completed', '#173a5e'],
];

$out = public_path('images/certificate-layouts');
@mkdir($out, 0755, true);
$work = sys_get_temp_dir().'/certificate-thumbnails';
@mkdir($work, 0700, true);

foreach (array_keys(CertificateTemplate::LAYOUTS) as $layout) {
    [$heading, $body, $accent] = $wording[$layout] ?? $wording['classic'];
    $template = new CertificateTemplate(['name' => $layout, 'layout' => $layout, 'heading' => $heading, 'body_line' => $body,
        'accent' => $accent, 'show_grade' => true, 'show_qr' => true]);

    file_put_contents("{$work}/{$layout}.pdf", app(Credentials::class)->previewCertificate($template));
    // 600 pixels across: twice the width a card shows it at.
    exec(sprintf('pdftoppm -png -singlefile -scale-to-x 600 -scale-to-y -1 %s %s', escapeshellarg("{$work}/{$layout}.pdf"), escapeshellarg("{$work}/{$layout}")), $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, "pdftoppm failed for {$layout}; is poppler installed?\n");
        exit(1);
    }

    $image = imagecreatefrompng("{$work}/{$layout}.png");
    imagewebp($image, "{$out}/{$layout}.webp", 82);
    imagedestroy($image);

    printf("Wrote public/images/certificate-layouts/%s.webp (%d KB)\n", $layout, filesize("{$out}/{$layout}.webp") / 1024);
}
