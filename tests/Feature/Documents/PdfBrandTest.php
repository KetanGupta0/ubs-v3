<?php

use App\Models\CertificateTemplate;
use App\Models\Invoice;
use App\Services\Billing\BillingDocuments;
use App\Services\Lms\Credentials;
use App\Support\PdfBrand;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Every PDF the platform makes carries the company logo: the BrandLogo lockup
 * as a PNG (public/brand/logo-pdf.png), handed to dompdf as data.
 */

/** Every view a PDF is rendered from; a new one is added here and must carry the logo. */
const PDF_VIEWS = [
    'billing.invoice', 'billing.receipt', 'billing.credit-note', 'billing.statement',
    'documents.proposal', 'documents.agreement', 'documents.change-request', 'documents.status-report', 'documents.service-report',
    'credentials.certificate', 'credentials.internship_certificate', 'credentials.offer_letter', 'credentials.mentor_evaluation', 'credentials.project_report',
    'credentials.certificates.regal', 'credentials.certificates.midnight', 'credentials.certificates.heritage', 'credentials.certificates.laurel',
    'exports.table', 'reports.college-monthly',
];

function viewSource(string $view): string
{
    return File::get(resource_path('views/'.str_replace('.', '/', $view).'.blade.php'));
}

it('knows every view a PDF is rendered from', function () {
    $rendered = collect(File::allFiles(app_path()))
        ->flatMap(fn ($file) => preg_match_all("/loadView\\('([a-z._-]+)'/", $file->getContents(), $m) ? $m[1] : [])
        // The internship documents are chosen by kind in Credentials::renderInternshipDocument(),
        // and a certificate's view by its design's layout.
        ->merge(['credentials.internship_certificate', 'credentials.offer_letter', 'credentials.mentor_evaluation', 'credentials.project_report'])
        ->merge(collect(CertificateTemplate::LAYOUTS)->keys()->map(fn (string $layout) => (new CertificateTemplate(['layout' => $layout]))->view()))
        ->unique()->sort()->values()->all();

    expect($rendered)->toBe(collect(PDF_VIEWS)->sort()->values()->all());
});

it('puts the logo on every PDF layout, itself or through the letterhead it includes', function () {
    foreach (PDF_VIEWS as $view) {
        $source = viewSource($view);
        preg_match_all("/@include\\('([a-z._-]+)'/", $source, $includes);
        $all = $source.collect($includes[1])->map(fn ($partial) => viewSource($partial))->implode('');

        expect(str_contains($all, 'PdfBrand::img('))->toBeTrue("{$view} has no logo");
    }
});

it('draws the logo at its own proportions, as data dompdf needs no file access for', function () {
    [$width, $height] = getimagesize(public_path(PdfBrand::LOGO));

    expect((string) PdfBrand::img(11))->toStartWith('<img src="data:image/png;base64,')
        ->toContain('height: 11mm; width: '.round(11 * $width / $height, 2).'mm;');
});

it('has a white logo for a dark ground, the same lockup at the same proportions', function () {
    expect(getimagesize(public_path(PdfBrand::LOGO_LIGHT)))->toMatchArray(array_slice(getimagesize(public_path(PdfBrand::LOGO)), 0, 2))
        ->and((string) PdfBrand::img(9, light: true))
        ->toContain(base64_encode((string) file_get_contents(public_path(PdfBrand::LOGO_LIGHT))))
        ->not->toBe((string) PdfBrand::img(9));
});

it('embeds the logo in a rendered invoice and certificate', function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('private');
    [$width, $height] = getimagesize(public_path(PdfBrand::LOGO));

    $invoice = Storage::disk('private')->get(app(BillingDocuments::class)->invoice(Invoice::query()->firstOrFail()));
    $certificate = app(Credentials::class)->previewCertificate(new CertificateTemplate(['name' => 'Default', 'show_qr' => true]));

    foreach ([$invoice, $certificate] as $pdf) {
        expect($pdf)->toContain("/Width {$width}")->toContain("/Height {$height}");
    }

    // Still one page, logo and all (CLAUDE.md: a certificate is one page).
    expect(preg_match_all('/\/Type\s*\/Page[^s]/', $certificate))->toBe(1);
});
