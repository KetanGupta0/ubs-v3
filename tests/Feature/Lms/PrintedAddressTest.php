<?php

use App\Models\CertificateTemplate;
use App\Services\Lms\Credentials;
use App\Services\Lms\ResultCard;
use App\Support\Monitoring\Health;
use App\Support\Printed;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;

/*
 * A certificate's QR code and "verify at" line can never be corrected once
 * printed. While the site answers on a temporary domain before it moves to its
 * own, documents print the permanent address (company.printed_url) instead.
 */

afterEach(fn () => @unlink(storage_path('framework/scheduler-heartbeat')));

/** Credentials that note every address it puts in a QR code. */
function credentialsNotingQr(): Credentials
{
    return new class(app(ResultCard::class)) extends Credentials
    {
        /** @var array<int, string> */
        public array $encoded = [];

        protected function qrDataUri(string $url): string
        {
            $this->encoded[] = $url;

            return parent::qrDataUri($url);
        }
    };
}

it('prints the permanent address in the QR code and the verify line, whatever address the site is on', function () {
    config(['app.url' => 'https://gold-woodcock-283829.hostingersite.com', 'company.printed_url' => 'https://unboundbytesolution.com/']);
    $credentials = credentialsNotingQr();

    foreach (array_keys(CertificateTemplate::LAYOUTS) as $layout) {
        $credentials->previewCertificate(new CertificateTemplate(['layout' => $layout, 'show_qr' => true]));
    }

    expect(array_unique($credentials->encoded))->toBe(['https://unboundbytesolution.com/verify/SAMPLE0000'])
        ->and(Printed::url('/verify'))->toBe('https://unboundbytesolution.com/verify');
});

it('prints the address the site is reached at when no permanent one is set', function () {
    config(['company.printed_url' => null]);
    $credentials = credentialsNotingQr();

    $credentials->previewCertificate(new CertificateTemplate(['show_qr' => true]));

    expect($credentials->encoded)->toBe([url('/verify/SAMPLE0000')]);
});

it('leaves no document to work out the address for itself', function () {
    $views = collect(File::allFiles(resource_path('views')))
        // The bare helper, not Printed::url().
        ->filter(fn ($file) => preg_match("~(?<![:\\w])url\\('/verify~", $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()->all();

    expect($views)->toBe([]);
});

it('knows a hosting provider\'s temporary address', function () {
    expect(Printed::isTemporary('https://gold-woodcock-283829.hostingersite.com'))->toBeTrue()
        ->and(Printed::isTemporary('https://unboundbytesolution.com'))->toBeFalse()
        ->and(Printed::isTemporary('https://hostingersite.com.example.org'))->toBeFalse()
        ->and(Printed::isTemporary(null))->toBeFalse();
});

it('warns in preflight while the site is on a temporary address with nothing set, and fails a printed address that is not HTTPS', function () {
    $this->seed(PermissionSeeder::class);
    Health::beat();

    config(['app.url' => 'https://gold-woodcock-283829.hostingersite.com', 'company.printed_url' => null]);
    $this->artisan('app:preflight')->expectsOutputToContain('set PRINTED_URL to the domain it will keep');

    config(['company.printed_url' => 'https://unboundbytesolution.com']);
    $this->artisan('app:preflight')->expectsOutputToContain('Printed as https://unboundbytesolution.com');

    config(['company.printed_url' => 'unboundbytesolution.com']);
    $this->artisan('app:preflight')->expectsOutputToContain('it must be the full https:// address')->assertFailed();
});
