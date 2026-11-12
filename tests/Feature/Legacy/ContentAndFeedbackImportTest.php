<?php

use App\Models\CourseReview;
use App\Models\Faq;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Page;
use App\Models\SeoOverride;
use App\Models\Setting;
use App\Models\TeamMember;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    LegacyDatabase::createContentAndFeedback();
    Storage::fake('local');
    Storage::fake('public');

    // A tiny real JPEG, so the photo can be re-encoded.
    $image = imagecreatetruecolor(40, 40);
    ob_start();
    imagejpeg($image);
    $this->jpeg = ob_get_clean();
    $this->storage = LegacyDatabase::storage(['app/public/team/asha.jpg' => $this->jpeg]);
});

afterEach(fn () => File::deleteDirectory($this->storage));

function importCms($test): array
{
    $test->artisan('legacy:import', ['--all-generation-one' => true, '--storage' => $test->storage])->assertSuccessful();
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('brings reviews across with their status and reply, none shown publicly without consent', function () {
    $student = LegacyDatabase::user();
    $program = LegacyDatabase::insert('training_programs', ['title' => 'Data course', 'slug' => 'data-course']);
    LegacyDatabase::insert('reviews', ['student_id' => $student, 'program_id' => $program, 'rating' => 9, 'title' => 'Great', 'body' => '<p>Loved it</p>', 'status' => 'approved', 'admin_reply' => 'Thank you!']);

    importCms($this);

    $review = CourseReview::query()->sole();
    expect($review->rating)->toBe(5)
        ->and($review->status)->toBe('approved')
        ->and($review->body)->toBe('Loved it')
        ->and($review->may_publish)->toBeFalse()
        ->and($review->reply)->toBe('Thank you!')
        ->and(CourseReview::query()->public()->count())->toBe(0);
});

it('turns enquiries into leads with a reference, the right status and the note', function () {
    LegacyDatabase::insert('contact_queries', ['name' => 'Ravi', 'email' => 'Ravi@Example.test', 'phone' => '9876543210', 'subject' => 'Clinic software', 'message' => 'Need a quote', 'status' => 'replied', 'admin_note' => 'Called back', 'created_at' => '2026-05-30 10:00:00']);

    importCms($this);

    $lead = Lead::query()->sole();
    expect($lead->reference)->not->toBeEmpty()
        ->and($lead->email)->toBe('ravi@example.test')
        ->and($lead->mobile)->toBe('+919876543210')
        ->and($lead->status)->toBe('contacted')
        ->and($lead->message)->toBe("Subject: Clinic software\n\nNeed a quote")
        ->and($lead->created_at->toDateString())->toBe('2026-05-30')
        ->and(LeadNote::query()->sole()->body)->toBe('From the old site: Called back');
});

it('adds new FAQs as drafts and keeps v3 answers to questions it already has', function () {
    Faq::query()->create(['question' => 'Who owns the code?', 'answer' => 'You do.', 'group' => 'general']);
    LegacyDatabase::insert('faqs', ['question' => 'who owns the code', 'answer' => 'Old answer']);
    LegacyDatabase::insert('faqs', ['question' => 'Do you offer EMI?', 'answer' => '<p>Yes, <b>three</b> instalments.</p>']);

    $report = importCms($this);

    expect(Faq::query()->where('question', 'Who owns the code?')->sole()->answer)->toBe('You do.')
        ->and(Faq::query()->where('question', 'Do you offer EMI?')->sole()->is_published)->toBeFalse()
        ->and(Faq::query()->where('question', 'Do you offer EMI?')->sole()->answer)->toBe('Yes, **three** instalments.')
        ->and(collect($report['steps']['cms']['warnings'])->pluck('code'))->toContain('faq_duplicate');
});

it('brings the team across with their photo re-encoded, and where they appear', function () {
    LegacyDatabase::insert('team_members', ['name' => 'Asha Verma', 'role' => 'Lead mentor', 'type' => 'both', 'tech_stack' => 'Laravel, Vue,  MySQL', 'avatar_url' => 'storage/team/asha.jpg', 'sort_order' => 2]);
    Http::fake(['https://cdn.example.test/*' => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
    LegacyDatabase::insert('team_members', ['name' => 'Ravi', 'role' => 'Trainer', 'type' => 'mentor', 'avatar_url' => 'https://cdn.example.test/ravi.jpg']);

    importCms($this);

    $asha = TeamMember::query()->where('name', 'Asha Verma')->sole();
    expect($asha->on_about)->toBeTrue()
        ->and($asha->is_mentor)->toBeTrue()
        ->and($asha->skills)->toBe(['Laravel', 'Vue', 'MySQL'])
        ->and($asha->photo_path)->toStartWith('team/');
    Storage::disk('public')->assertExists($asha->photo_path);

    $ravi = TeamMember::query()->where('name', 'Ravi')->sole();
    expect($ravi->on_about)->toBeFalse()->and($ravi->photo_path)->not->toBeNull();
});

it('converts old pages to drafts in Markdown, with nothing that could run', function () {
    LegacyDatabase::insert('custom_pages', ['title' => 'Campus drive', 'slug' => 'campus-drive', 'is_published' => true, 'show_in_nav' => true,
        'content' => '<h2>Dates</h2><p>Join us <a href="javascript:alert(1)">here</a>.</p><script>steal()</script><p onclick="x()">Bring ID.</p>',
        'meta_description' => 'The drive']);

    $report = importCms($this);

    $page = Page::query()->sole();
    expect($page->slug)->toBe('campus-drive')
        ->and($page->isPublished())->toBeFalse()
        ->and($page->blocks[0]['markdown'])->toBe("## Dates\n\nJoin us here.\n\nBring ID.")
        ->and($page->seo_description)->toBe('The drive')
        ->and(collect($report['steps']['cms']['warnings'])->firstWhere('code', 'page_draft')['message'])->toContain('Site menus');
});

it('fills empty settings only, never imports a secret, and lists templates without carrying them', function () {
    Setting::put('company.phone', '+91 11111 11111', 'company');
    LegacyDatabase::insert('settings', ['key' => 'contact_email', 'value' => 'hello@old.example.test']);
    LegacyDatabase::insert('settings', ['key' => 'contact_phone', 'value' => '+91 22222 22222']);
    LegacyDatabase::insert('settings', ['key' => 'social_linkedin', 'value' => 'https://www.linkedin.com/company/x']);
    LegacyDatabase::insert('settings', ['key' => 'razorpay_secret', 'value' => 'rzp_live_SECRET']);
    LegacyDatabase::insert('settings', ['key' => 'stat_placement', 'value' => '98%']);
    LegacyDatabase::insert('page_metas', ['page_key' => 'about', 'page_label' => 'About', 'page_url' => 'https://old.example.test/about', 'meta_title' => 'About the team']);
    LegacyDatabase::insert('email_templates', ['key' => 'account_created', 'name' => 'Account', 'subject' => 's', 'html_body' => 'Your password is {{password}}']);

    $report = importCms($this);

    expect(Setting::get('company.email'))->toBe('hello@old.example.test')
        ->and(Setting::get('company.phone'))->toBe('+91 11111 11111')
        ->and(Setting::get('site.social.linkedin'))->toBe('https://www.linkedin.com/company/x')
        ->and(json_encode(Setting::all_values()))->not->toContain('rzp_live_SECRET')
        ->and(SeoOverride::query()->where('path', '/about')->sole()->title)->toBe('About the team');

    $codes = collect($report['steps']['cms']['warnings'])->pluck('code', 'source');
    expect($codes['settings:razorpay_secret'])->toBe('setting_secret')
        ->and($codes['settings:stat_placement'])->toBe('setting_not_carried')
        ->and($codes['email_templates:account_created'])->toBe('template_not_carried')
        ->and(json_encode($report))->not->toContain('rzp_live_SECRET');
});
