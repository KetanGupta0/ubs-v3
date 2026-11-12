<?php

use App\Models\Announcement;
use App\Models\Batch;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    LegacyDatabase::createContent();
    Storage::fake('local');
    Storage::fake('private');

    $this->student = LegacyDatabase::user(['email' => 'learner@example.test']);
    $this->program = LegacyDatabase::insert('training_programs', ['title' => 'Data course', 'slug' => 'data-course']);
    $this->batch = LegacyDatabase::insert('batches', ['program_id' => $this->program, 'name' => 'June', 'start_date' => now()->subMonth()->toDateString()]);
    $this->other = LegacyDatabase::insert('batches', ['program_id' => $this->program, 'name' => 'July', 'start_date' => now()->toDateString()]);

    // A real, tiny PDF header is enough for its type to be read from its bytes.
    $this->storage = LegacyDatabase::storage([
        'app/public/materials/week1.pdf' => "%PDF-1.4\n%fake\n",
    ]);
});

afterEach(fn () => File::deleteDirectory($this->storage));

function importContent($test, array $options = []): void
{
    $test->artisan('legacy:import', ['--all-generation-one' => true, '--storage' => $test->storage, ...$options])->assertSuccessful();
}

function contentReport(): array
{
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('copies a material into v3, checked, typed from its bytes, and kept to its batch', function () {
    LegacyDatabase::insert('study_materials', ['batch_id' => $this->batch, 'title' => 'Week 1 slides', 'file_path' => 'materials/week1.pdf', 'original_name' => 'week1.exe', 'mime_type' => 'application/x-msdownload']);

    importContent($this);

    $material = Material::query()->sole();
    expect($material->mime)->toBe('application/pdf')
        ->and($material->batch_id)->toBe(Batch::query()->where('name', 'June')->value('id'))
        ->and($material->path)->toStartWith("courses/{$material->course_id}/")
        ->and(Storage::disk('private')->get($material->path))->toBe("%PDF-1.4\n%fake\n")
        ->and(contentReport()['steps']['materials']['files'])->toBe(1);
});

it('lists a material whose file is missing, and imports nothing for it', function () {
    $missing = LegacyDatabase::insert('study_materials', ['batch_id' => $this->batch, 'title' => 'Gone', 'file_path' => 'materials/gone.pdf', 'original_name' => 'gone.pdf']);

    importContent($this);

    expect(Material::query()->count())->toBe(0)
        ->and(collect(contentReport()['steps']['materials']['warnings'])->where('code', 'file_missing')->pluck('source')->all())->toBe(["study_materials#{$missing}"]);
});

it('copies nothing on a dry run, and says materials need the storage copy', function () {
    LegacyDatabase::insert('study_materials', ['batch_id' => $this->batch, 'title' => 'Week 1', 'file_path' => 'materials/week1.pdf', 'original_name' => 'w.pdf']);

    $this->artisan('legacy:import', ['--dry-run' => true, '--storage' => $this->storage])->assertSuccessful();
    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and(contentReport()['steps']['materials']['created'])->toBe(1);

    $this->artisan('legacy:import', ['--dry-run' => true])->assertSuccessful();
    expect(contentReport()['steps']['materials']['warnings'][0]['code'])->toBe('storage_not_given');
});

it('sends each notice to the same people it reached on v2', function () {
    LegacyDatabase::insert('notices', ['title' => 'Holiday', 'body' => '<p>Closed on Monday.</p>', 'target_type' => 'all', 'type' => 'info', 'created_at' => '2026-06-01 09:00:00']);
    LegacyDatabase::insert('notices', ['title' => 'June batch', 'body' => 'Room change', 'target_type' => 'batch', 'target_id' => $this->batch, 'type' => 'urgent']);
    LegacyDatabase::insert('notices', ['title' => 'For you', 'body' => 'See me', 'target_type' => 'student', 'target_id' => $this->student]);

    importContent($this);

    $everyone = Announcement::query()->where('title', 'Holiday')->sole();
    expect($everyone->audience)->toBe('everyone')
        ->and($everyone->body)->toBe('Closed on Monday.')
        ->and($everyone->published_at->toDateTimeString())->toBe('2026-06-01 09:00:00');

    $batch = Announcement::query()->where('title', 'June batch')->sole();
    expect($batch->audience)->toBe('batch')
        ->and($batch->batch_id)->toBe(Batch::query()->where('name', 'June')->value('id'))
        ->and($batch->is_pinned)->toBeTrue();

    $personal = Announcement::query()->where('title', 'For you')->sole();
    expect($personal->audience)->toBe('student')
        ->and($personal->user_id)->toBe(User::query()->where('email', 'learner@example.test')->value('id'))
        ->and($personal->batch_id)->toBeNull();
});
