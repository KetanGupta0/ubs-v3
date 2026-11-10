<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InternshipDocument;
use App\Models\Permission;
use App\Models\User;
use App\Services\Lms\Credentials;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $this->course = Course::query()->create([
        'title' => 'Full stack internship', 'slug' => 'tpl-'.uniqid(), 'type' => 'internship',
        'tagline' => 'x', 'summary' => 'y', 'is_published' => true,
    ]);
    $this->other = Course::query()->create([
        'title' => 'SQL mastery', 'slug' => 'tpl-other-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'is_published' => true,
    ]);

    $this->enrol = function (Course $course): Enrollment {
        $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'B', 'code' => 'TP-'.uniqid(), 'starts_on' => today()]);

        return Enrollment::query()->create([
            'user_id' => User::factory()->student()->create()->id, 'course_id' => $course->id,
            'batch_id' => $batch->id, 'enrolled_at' => now(), 'has_paid' => true,
        ]);
    };

    $this->template = fn (array $attributes = []) => CertificateTemplate::query()->create([
        'name' => 'Design', 'heading' => 'Certificate of internship', 'body_line' => 'has completed',
        'accent' => '#0f766e', ...$attributes,
    ]);
});

it('issues with the course\'s own design, then the default, then the built-in one', function () {
    $issue = fn (Course $course) => app(Credentials::class)->issueCertificate(($this->enrol)($course)->load('course'), $this->owner, force: true);

    expect($issue($this->course)->certificate_template_id)->toBeNull();

    $default = ($this->template)(['name' => 'Default']);
    $own = ($this->template)(['name' => 'Internships', 'course_id' => $this->course->id]);

    expect($issue($this->course)->certificate_template_id)->toBe($own->id)
        ->and($issue($this->other)->certificate_template_id)->toBe($default->id);

    // A design that is switched off is passed over.
    $own->forceFill(['is_active' => false])->save();
    expect($issue($this->course)->certificate_template_id)->toBe($default->id);
});

it('never changes a certificate already issued when its design changes', function () {
    $design = ($this->template)(['course_id' => $this->course->id]);
    $certificate = app(Credentials::class)->issueCertificate(($this->enrol)($this->course)->load('course'), $this->owner, force: true);
    $before = Storage::disk('private')->get($certificate->pdf_path);

    $this->actingAs($this->owner)->post("/admin/certificate-templates/{$design->id}", [
        'name' => 'Design', 'course_id' => $this->course->id, 'heading' => 'Completely different heading',
        'body_line' => 'has completed', 'accent' => '#be123c',
    ])->assertSessionHasNoErrors();

    expect(app(Credentials::class)->renderCertificate($certificate->fresh()))->toBe($certificate->pdf_path)
        ->and(Storage::disk('private')->get($certificate->pdf_path))->toBe($before);
});

it('saves a design with a background and signature, re-encoded on the private disk', function () {
    $this->actingAs($this->owner)->post('/admin/certificate-templates', [
        'name' => 'Internships 2026',
        'course_id' => $this->course->id,
        'heading' => 'Certificate of internship',
        'body_line' => 'has successfully completed',
        'signatory_name' => 'Ketan Gupta',
        'signatory_title' => 'Director',
        'accent' => '#0F766E',
        'background' => UploadedFile::fake()->image('paper.jpg', 3000, 2100),
        'signature' => UploadedFile::fake()->image('sign.png', 1200, 300),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $template = CertificateTemplate::query()->sole();

    expect($template->accent)->toBe('#0f766e')
        ->and(Storage::disk('private')->exists($template->background_path))->toBeTrue()
        ->and(str_ends_with($template->background_path, '.jpg'))->toBeTrue()
        ->and(str_ends_with($template->signature_path, '.png'))->toBeTrue()
        // Wider than the cap, so it was scaled down on the way in.
        ->and(getimagesizefromstring(Storage::disk('private')->get($template->signature_path))[0])->toBe(900)
        ->and(AuditLog::query()->where('action', 'created')->where('subject_type', CertificateTemplate::class)->exists())->toBeTrue();
});

it('previews a design as a PDF without issuing anything', function () {
    $design = ($this->template)(['course_id' => $this->course->id, 'signatory_name' => 'Ketan Gupta']);

    $response = $this->actingAs($this->owner)->get("/admin/certificate-templates/{$design->id}/preview")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with($response->getContent(), '%PDF'))->toBeTrue()
        ->and(Certificate::query()->count())->toBe(0);
});

it('refuses a colour that is not a colour', function () {
    $this->actingAs($this->owner)->post('/admin/certificate-templates', [
        'name' => 'Bad', 'heading' => 'x', 'body_line' => 'y', 'accent' => 'red; background:url(x)',
    ])->assertSessionHasErrors('accent');
});

it('is for staff who manage the catalogue', function () {
    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'catalogue.view')->sole());

    $this->actingAs($viewer->fresh())->get('/admin/certificate-templates')->assertForbidden();
    $this->actingAs($this->owner)->get('/admin/certificate-templates')->assertOk();
});

/*
 * dompdf ignores `inset` and mishandles `bottom`. Until November 2026 that
 * left every certificate with an unclosed border, no signatory, no
 * verification code and no number on the page, and a blank second page.
 * A certificate is one page; a second page means the layout has spilled.
 */
function pdfPages(string $pdf): int
{
    return preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf);
}

it('fits a certificate on one page, with the built-in design and with an uploaded one', function () {
    $credentials = app(Credentials::class);

    expect(pdfPages($credentials->previewCertificate(new CertificateTemplate(['show_qr' => true, 'show_grade' => true]))))->toBe(1);

    $this->actingAs($this->owner)->post('/admin/certificate-templates', [
        'name' => 'With artwork', 'heading' => 'Certificate of internship', 'body_line' => 'has completed',
        'signatory_name' => 'Ketan Gupta', 'signatory_title' => 'Director', 'accent' => '#0f766e',
        'background' => UploadedFile::fake()->image('paper.jpg', 1754, 1240),
        'signature' => UploadedFile::fake()->image('sign.png', 600, 160),
    ]);

    expect(pdfPages($credentials->previewCertificate(CertificateTemplate::query()->sole())))->toBe(1);
});

it('fits the internship certificate on one page too', function () {
    $document = new InternshipDocument([
        'number' => 'UBS/INT/SAMPLE', 'verification_code' => 'INTSAMPLE00', 'kind' => 'certificate',
    ]);

    $pdf = Pdf::loadView('credentials.internship_certificate', [
        'document' => $document,
        'payload' => [
            'student' => 'Asha Verma', 'college' => 'Example Institute of Technology',
            'title' => 'Full stack web development internship', 'startsOn' => '2026-09-01', 'endsOn' => '2026-10-31',
            'mode' => 'remote', 'durationLabel' => '8 weeks',
            'projectFocus' => 'A booking platform with payments, built and deployed by the student.',
        ],
        'company' => ['name' => 'Unboundbyte Solutions Private Limited', 'trading' => 'Unboundbyte Solutions'],
    ])->setPaper('a4', 'landscape')->output();

    expect(pdfPages($pdf))->toBe(1);
});
