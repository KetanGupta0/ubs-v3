<?php

use App\Models\Certificate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    LegacyDatabase::createCertificates();
    Storage::fake('local');
    Storage::fake('private');

    $this->student = LegacyDatabase::user(['name' => 'Asha Verma', 'email' => 'asha@example.test']);
    $program = LegacyDatabase::insert('training_programs', ['title' => 'Web development internship', 'slug' => 'web-dev']);
    $this->batch = LegacyDatabase::insert('batches', ['program_id' => $program, 'name' => 'June']);
    $this->program = $program;

    // Mixed case, as v2's tokens are.
    $this->token = 'aB3'.Str::random(61);
    $this->storage = LegacyDatabase::storage(['app/public/certificates/legacy-42.pdf' => "%PDF-1.4\nold design\n"]);
});

afterEach(fn () => File::deleteDirectory($this->storage));

function importCertificates($test): array
{
    $test->artisan('legacy:import', ['--all-generation-one' => true, '--storage' => $test->storage])->assertSuccessful();
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('keeps the printed number and the old QR token, and keeps the old PDF', function () {
    LegacyDatabase::insert('certificates', ['student_id' => $this->student, 'batch_id' => $this->batch, 'program_id' => $this->program,
        'certificate_number' => 'CERT-2025-00042', 'token' => $this->token, 'issued_at' => '2025-08-01 10:00:00', 'is_legacy' => true, 'pdf_path' => 'certificates/legacy-42.pdf']);

    importCertificates($this);

    $certificate = Certificate::query()->sole();
    expect($certificate->number)->toBe('CERT-2025-00042')
        ->and($certificate->legacy_token)->toBe($this->token)
        ->and(strlen($certificate->verification_code))->toBeGreaterThan(0)
        ->and($certificate->issued_at->toDateString())->toBe('2025-08-01')
        ->and(Storage::disk('private')->get($certificate->pdf_path))->toBe("%PDF-1.4\nold design\n");
});

it('answers the old QR link at /verify, exactly as printed, and nothing else', function () {
    LegacyDatabase::insert('certificates', ['student_id' => $this->student, 'batch_id' => $this->batch, 'program_id' => $this->program,
        'certificate_number' => 'CERT-2025-00042', 'token' => $this->token]);

    importCertificates($this);

    $this->get("/verify/{$this->token}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('result.found', true)
        ->where('result.valid', true)
        ->where('result.number', 'CERT-2025-00042')
        ->where('result.holder', 'Asha Verma'));

    // The same token in another case is a different token.
    $unknown = $this->get('/verify/'.strtoupper($this->token))->assertOk()->viewData('page')['props']['result'];
    $never = $this->get('/verify/'.Str::random(64))->assertOk()->viewData('page')['props']['result'];

    expect($unknown)->toBe(['found' => false])->and($never)->toBe($unknown);

    // And the v3 code works too.
    $code = Certificate::query()->sole()->verification_code;
    $this->get("/verify/{$code}")->assertInertia(fn ($page) => $page->where('result.found', true));
});

it('keeps a revoked certificate revoked, still answering and saying so', function () {
    LegacyDatabase::insert('certificates', ['student_id' => $this->student, 'batch_id' => $this->batch, 'program_id' => $this->program,
        'certificate_number' => 'CERT-2025-00043', 'token' => $this->token, 'revoked_at' => '2025-09-01 10:00:00', 'revocation_reason' => 'Issued in error']);

    importCertificates($this);

    $this->get("/verify/{$this->token}")->assertInertia(fn ($page) => $page
        ->where('result.found', true)
        ->where('result.valid', false)
        ->where('result.revokedReason', 'Issued in error'));
});

it('lists a certificate without its old PDF, and one it cannot place', function () {
    $noPdf = LegacyDatabase::insert('certificates', ['student_id' => $this->student, 'batch_id' => $this->batch, 'program_id' => $this->program, 'certificate_number' => 'CERT-1', 'token' => Str::random(64)]);
    $orphan = LegacyDatabase::insert('certificates', ['student_id' => 999, 'program_id' => $this->program, 'certificate_number' => 'CERT-2', 'token' => Str::random(64)]);

    $warnings = collect(importCertificates($this)['steps']['certificates']['warnings']);

    expect($warnings->where('code', 'no_pdf')->pluck('source')->all())->toBe(["certificates#{$noPdf}"])
        ->and($warnings->where('code', 'missing_reference')->pluck('source')->all())->toBe(["certificates#{$orphan}"])
        ->and(Certificate::query()->count())->toBe(1);
});
