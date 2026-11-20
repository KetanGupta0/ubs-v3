<?php

use App\Models\Batch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Lms\Credentials;
use App\Support\CertificateArt;
use App\Support\PdfBrand;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The premium certificate layouts: Regal, Midnight, Heritage and Laurel, drawn
 * whole with gold ornaments and their own typefaces. A migration adds a design
 * in each, switched off, so nothing issued changes until somebody turns one on.
 */

beforeEach(function () {
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->premium = ['regal', 'midnight', 'heritage', 'laurel'];

    $this->pages = fn (string $pdf): int => preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf);
    $this->fonts = fn (string $pdf): array => preg_match_all('~/BaseFont\s*/(?:[A-Z]+\+)?([A-Za-z0-9-]+)~', $pdf, $m) ? array_values(array_unique($m[1])) : [];
});

it('adds a design in each premium layout, switched off, for every course, with nobody named to sign', function () {
    $designs = CertificateTemplate::query()->orderBy('id')->get();

    expect($designs->pluck('layout')->all())->toBe($this->premium)
        ->and($designs->pluck('name')->all())->toBe(['Regal', 'Midnight', 'Heritage', 'Laurel'])
        ->and($designs->every(fn (CertificateTemplate $design) => ! $design->is_active && $design->course_id === null && $design->signatory_name === null))->toBeTrue()
        // Switched off, so a certificate issued now looks as it did before.
        ->and(CertificateTemplate::forCourse(null))->toBeNull();
});

it('draws every layout on one page, with the logo and the typefaces it names, embedded and subset', function () {
    [$width, $height] = getimagesize(public_path(PdfBrand::LOGO));
    $display = ['regal' => 'GreatVibes-Regular', 'midnight' => 'PlayfairDisplay-Italic', 'heritage' => 'PlayfairDisplay-Bold', 'laurel' => 'CormorantGaramond-MediumItalic'];

    foreach (CertificateTemplate::query()->with('course')->get() as $design) {
        $pdf = app(Credentials::class)->previewCertificate($design);
        $fonts = ($this->fonts)($pdf);

        expect(($this->pages)($pdf))->toBe(1, "{$design->layout} spills onto a second page")
            ->and(str_contains($pdf, "/Width {$width}") && str_contains($pdf, "/Height {$height}"))->toBeTrue("{$design->layout} has no logo")
            ->and($fonts)->toContain($display[$design->layout], 'Cinzel-SemiBold')
            // A typeface dompdf could not read falls back to a core font, which is never embedded.
            ->and(array_filter($fonts, fn ($font) => preg_match('/^(Times|Helvetica|Courier)/', $font)))->toBe([])
            ->and(preg_match('~/BaseFont\s*/[A-Z]+\+Cinzel-SemiBold~', $pdf))->toBe(1, 'only the letters used go in');
    }
});

it('keeps each layout to one page with a long name and course, a batch, and no grade or QR code', function () {
    $certificate = new Certificate([
        'number' => 'UBS/CERT/2026/01234', 'verification_code' => 'K7Q2M9XW4T', 'issued_at' => now(), 'grade' => null, 'final_percent' => null,
        'title' => 'Advanced full stack web development with React, Node.js and cloud deployment internship, with a capstone project for a real client',
    ]);
    $certificate->setRelation('user', new User(['name' => 'Venkata Satya Sai Lakshmi Narasimha Prasad Rao Chowdary']));
    $certificate->setRelation('batch', new Batch(['starts_on' => today()->subMonths(6), 'ends_on' => today()->subMonth()]));
    $render = fn (CertificateTemplate $design) => (fn () => $this->certificatePdf($certificate, $design))->call(app(Credentials::class));

    foreach (CertificateTemplate::query()->get() as $design) {
        $design->show_qr = false;

        expect(($this->pages)($render($design)))->toBe(1, "{$design->layout} spills onto a second page");
    }
});

it('issues with a premium design once it is switched on, and keeps that look', function () {
    $course = Course::query()->create(['title' => 'Full stack internship', 'slug' => 'lay-'.uniqid(), 'type' => 'internship', 'tagline' => 'x', 'summary' => 'y', 'is_published' => true]);
    $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'B', 'code' => 'LY-'.uniqid(), 'starts_on' => today()]);
    $enrolment = Enrollment::query()->create(['user_id' => User::factory()->student()->create()->id, 'course_id' => $course->id, 'batch_id' => $batch->id, 'enrolled_at' => now(), 'has_paid' => true]);

    $regal = CertificateTemplate::query()->where('layout', 'regal')->sole();
    $regal->forceFill(['is_active' => true])->save();

    $certificate = app(Credentials::class)->issueCertificate($enrolment->load('course'), $this->owner, force: true);

    expect($certificate->certificate_template_id)->toBe($regal->id)
        ->and(($this->fonts)(Storage::disk('private')->get($certificate->pdf_path)))->toContain('GreatVibes-Regular');
});

it('saves the layout chosen, keeps it when none is sent, and refuses one that does not exist', function () {
    $fields = ['name' => 'Gold', 'heading' => 'Certificate of Completion', 'body_line' => 'has completed', 'accent' => '#1e2a4a'];

    $this->actingAs($this->owner)->post('/admin/certificate-templates', [...$fields, 'layout' => 'regal'])->assertSessionHasNoErrors();
    $design = CertificateTemplate::query()->where('name', 'Gold')->sole();
    expect($design->layout)->toBe('regal');

    $this->actingAs($this->owner)->post("/admin/certificate-templates/{$design->id}", [...$fields, 'heading' => 'Certificate of Merit'])->assertSessionHasNoErrors();
    expect($design->fresh()->only(['layout', 'heading']))->toBe(['layout' => 'regal', 'heading' => 'Certificate of Merit']);

    $this->actingAs($this->owner)->post('/admin/certificate-templates', [...$fields, 'name' => 'Odd', 'layout' => 'baroque'])->assertSessionHasErrors('layout');
    expect(CertificateTemplate::query()->where('name', 'Odd')->exists())->toBeFalse();
});

it('takes a background for Classic only, and keeps one saved before for a return to Classic', function () {
    $fields = ['heading' => 'Certificate of Completion', 'body_line' => 'has completed', 'accent' => '#1e2a4a'];
    $paper = fn () => UploadedFile::fake()->image('paper.jpg', 1754, 1240);

    $this->actingAs($this->owner)->post('/admin/certificate-templates', [...$fields, 'name' => 'Regal paper', 'layout' => 'regal', 'background' => $paper()]);
    expect(CertificateTemplate::query()->where('name', 'Regal paper')->sole()->background_path)->toBeNull();

    $this->actingAs($this->owner)->post('/admin/certificate-templates', [...$fields, 'name' => 'Classic paper', 'layout' => 'classic', 'background' => $paper()]);
    $design = CertificateTemplate::query()->where('name', 'Classic paper')->sole();
    expect($design->background_path)->not->toBeNull()
        ->and(app(Credentials::class)->previewCertificate($design))->toContain('/Width 1754');

    $this->actingAs($this->owner)->post("/admin/certificate-templates/{$design->id}", [...$fields, 'name' => 'Classic paper', 'layout' => 'laurel']);
    $design->refresh();
    expect($design->background_path)->not->toBeNull()
        ->and(app(Credentials::class)->previewCertificate($design))->not->toContain('/Width 1754');
});

it('says which design new certificates use, where more than one is switched on', function () {
    CertificateTemplate::query()->whereIn('layout', ['regal', 'laurel'])->update(['is_active' => true]);

    $this->actingAs($this->owner)->get('/admin/certificate-templates?per_page=50')->assertOk()
        ->assertInertia(fn ($page) => $page->where('table.rows', fn ($rows) => collect($rows)->where('inUse', true)->pluck('name')->all() === ['Laurel']
            && collect($rows)->where('is_active', true)->count() === 2));
});

it('offers every layout on the form, each with its picture', function () {
    $this->actingAs($this->owner)->get('/admin/certificate-templates/new')->assertOk()
        ->assertInertia(fn ($page) => $page->has('layouts', count(CertificateTemplate::LAYOUTS))
            ->where('layouts', fn ($layouts) => collect($layouts)->every(fn ($layout) => $layout['picture'] !== null
                && is_file(public_path(ltrim($layout['picture'], '/'))))));
});

it('prints the accent only where it reads at 4.5:1 against what it meets, else the layout\'s own colour', function () {
    expect(CertificateArt::ink('#1E2A4A', '#000000', '#ffffff'))->toBe('#1e2a4a')
        // Gold and a pale blue are too light for white words, or as words on white.
        ->and(CertificateArt::ink('#ffd700', '#0e1630', '#ffffff'))->toBe('#0e1630')
        ->and(CertificateArt::ink('#93c5fd', '#0e1630', '#ffffff'))->toBe('#0e1630')
        // #767676 is the lightest grey that still makes 4.5:1 with white.
        ->and(CertificateArt::ink('#767676', '#000000', '#ffffff'))->toBe('#767676')
        ->and(CertificateArt::ink('#777777', '#000000', '#ffffff'))->toBe('#000000')
        ->and(CertificateArt::ink('red', '#0e1630'))->toBe('#0e1630')
        ->and(CertificateArt::ink(null, '#0e1630'))->toBe('#0e1630')
        ->and(round(CertificateArt::contrast('#000000', '#ffffff'), 2))->toBe(21.0);

    // A slate blue carries white words, but not Midnight's grey dates and gold lettering.
    $slate = fn (string $layout) => (new CertificateTemplate(['layout' => $layout, 'accent' => '#4a5a8a']))->ink();

    expect($slate('laurel'))->toBe('#4a5a8a')
        ->and($slate('midnight'))->toBe('#0e1630')
        ->and((new CertificateTemplate(['layout' => 'midnight', 'accent' => '#0B3D2E']))->ink())->toBe('#0b3d2e')
        ->and((new CertificateTemplate(['layout' => 'heritage', 'accent' => '#ffd700']))->ink())->toBe('#6d1f2c')
        ->and((new CertificateTemplate(['layout' => 'classic', 'accent' => '#FFD700']))->ink())->toBe('#ffd700');
});

it('shrinks a long line in proportion, down to a floor', function () {
    expect(CertificateArt::fit('Asha Verma', 26, 58, 28))->toBe(58.0)
        ->and(CertificateArt::fit(str_repeat('a', 52), 26, 58, 28))->toBe(29.0)
        ->and(CertificateArt::fit(str_repeat('a', 90), 26, 58, 28))->toBe(28.0);
});
