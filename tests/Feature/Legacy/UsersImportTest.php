<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    Storage::fake('local');

    $this->genOneFile = tempnam(sys_get_temp_dir(), 'gen1');
});

afterEach(fn () => @unlink($this->genOneFile));

function importUsers($test, array $options = []): void
{
    $test->artisan('legacy:import', ['--only' => 'users', ...$options])->assertSuccessful();
}

it('brings students across with their profile, a normalised mobile and their history', function () {
    $id = LegacyDatabase::user(['email' => 'Asha.Verma@Example.TEST', 'phone' => '098765 43210', 'date_of_birth' => '2003-04-05']);
    file_put_contents($this->genOneFile, '');

    importUsers($this, ['--generation-one' => $this->genOneFile]);

    $user = User::query()->where('email', 'asha.verma@example.test')->sole();
    expect($user->role)->toBe(Role::Student)
        ->and($user->mobile)->toBe('+919876543210')
        ->and($user->is_owner)->toBeFalse()
        ->and($user->created_at->toDateString())->toBe('2026-05-20')
        ->and($user->profile->postal_code)->toBe('226001')
        ->and($user->profile->qualification)->toBe('B.Tech')
        ->and($user->profile->date_of_birth->toDateString())->toBe('2003-04-05');

    expect(DB::table('legacy_id_map')->where('source_table', 'users')->where('source_id', $id)->value('target_id'))->toBe($user->id);
});

it('keeps a generation 2 password exactly, so the student signs in as before', function () {
    LegacyDatabase::user(['email' => 'gen2@example.test']);
    file_put_contents($this->genOneFile, '');

    importUsers($this, ['--generation-one' => $this->genOneFile]);

    $user = User::query()->where('email', 'gen2@example.test')->sole();
    expect(Hash::check('old-site-password', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and(DB::table('legacy_id_map')->where('target_id', $user->id)->value('generation'))->toBe(2);
});

it('replaces a generation 1 password that was published, and asks for a new one', function () {
    LegacyDatabase::user(['email' => 'gen1@example.test']);
    file_put_contents($this->genOneFile, "GEN1@example.test\n");

    importUsers($this, ['--generation-one' => $this->genOneFile]);

    $user = User::query()->where('email', 'gen1@example.test')->sole();
    expect(Hash::check('old-site-password', $user->password))->toBeFalse()
        ->and($user->password)->not->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and(DB::table('legacy_id_map')->where('target_id', $user->id)->value('generation'))->toBe(1);
});

it('refuses a real run that does not say which accounts are generation 1', function () {
    LegacyDatabase::user();

    $this->artisan('legacy:import', ['--only' => 'users'])->assertFailed();
    expect(User::query()->count())->toBe(0);

    // --all-generation-one is the safe blanket answer.
    importUsers($this, ['--all-generation-one' => true]);
    expect(User::query()->sole()->must_change_password)->toBeTrue();
});

it('keeps Google-only accounts without a password, and links Google', function () {
    LegacyDatabase::user(['email' => 'google@example.test', 'has_password' => false, 'password' => 'x', 'google_id' => 'g-123', 'avatar' => 'https://lh3.example/a.png']);

    importUsers($this, ['--all-generation-one' => true]);

    $user = User::query()->where('email', 'google@example.test')->sole();
    expect($user->password)->toBeNull()
        ->and($user->must_change_password)->toBeFalse()
        ->and(SocialAccount::query()->where('provider', 'google')->sole()->provider_user_id)->toBe('g-123');
});

it('imports a blocked account as suspended, with the reason in the audit log', function () {
    LegacyDatabase::user(['email' => 'blocked@example.test', 'is_blocked' => true, 'block_reason' => 'Shared account']);

    importUsers($this, ['--all-generation-one' => true]);

    $user = User::query()->where('email', 'blocked@example.test')->sole();
    expect($user->status)->toBe(UserStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'user.suspended_on_import')->sole()->changes['reason']['to'])->toBe('Shared account');
});

it('brings administrators across without any permission and never as the owner', function () {
    LegacyDatabase::user(['email' => 'staff@example.test', 'role' => 'admin']);

    importUsers($this, ['--all-generation-one' => true]);

    $user = User::query()->where('email', 'staff@example.test')->sole();
    expect($user->role)->toBe(Role::Admin)
        ->and($user->is_owner)->toBeFalse()
        ->and($user->permissionKeys())->toBe([]);
});

it('leaves out a mobile that is malformed or already taken, and says so without the number', function () {
    LegacyDatabase::user(['email' => 'one@example.test', 'phone' => '9876501234']);
    $second = LegacyDatabase::user(['email' => 'two@example.test', 'phone' => '+91 98765 01234']);
    $third = LegacyDatabase::user(['email' => 'three@example.test', 'phone' => '12']);

    importUsers($this, ['--all-generation-one' => true]);

    expect(User::query()->where('email', 'one@example.test')->value('mobile'))->toBe('+919876501234')
        ->and(User::query()->where('email', 'two@example.test')->value('mobile'))->toBeNull()
        ->and(User::query()->where('email', 'three@example.test')->value('mobile'))->toBeNull();

    $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('legacy-reports')[0]), true);
    $warnings = collect($report['steps']['users']['warnings']);

    expect($warnings->where('code', 'duplicate_mobile')->pluck('source')->all())->toBe(["users#{$second}"])
        ->and($warnings->where('code', 'bad_mobile')->pluck('source')->all())->toBe(["users#{$third}"]);

    // The report can be shared: no email, name or number in it.
    $text = json_encode($report);
    expect($text)->not->toContain('@example.test')->not->toContain('98765')->not->toContain('Student');
});

it('links to an account that already exists in v3, such as the owner, without touching it', function () {
    $owner = User::factory()->owner()->create(['email' => 'owner@example.test', 'password' => 'owner-secret']);
    LegacyDatabase::user(['email' => 'owner@example.test', 'role' => 'admin']);

    importUsers($this, ['--all-generation-one' => true]);

    $owner->refresh();
    expect($owner->is_owner)->toBeTrue()
        ->and(Hash::check('owner-secret', $owner->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('runs again without duplicating, skips what has not changed, and never resets a password', function () {
    $id = LegacyDatabase::user(['email' => 'again@example.test', 'city' => 'Lucknow']);
    importUsers($this, ['--all-generation-one' => true]);
    $user = User::query()->where('email', 'again@example.test')->sole();

    // A credential was sent between rehearsals.
    $user->forceFill(['password' => 'chosen-later', 'must_change_password' => false])->save();

    importUsers($this, ['--all-generation-one' => true]);
    DB::connection('legacy')->table('users')->where('id', $id)->update(['city' => 'Kanpur']);
    importUsers($this, ['--all-generation-one' => true]);

    $user->refresh();
    expect(User::query()->count())->toBe(1)
        ->and($user->profile->city)->toBe('Kanpur')
        ->and(Hash::check('chosen-later', $user->password))->toBeTrue();
});

it('changes nothing and sends nothing on a dry run', function () {
    Notification::fake();
    LegacyDatabase::user();

    $this->artisan('legacy:import', ['--dry-run' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(DB::table('legacy_id_map')->count())->toBe(0);
    Notification::assertNothingSent();

    $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('legacy-reports')[0]), true);
    expect($report['dry_run'])->toBeTrue()
        ->and($report['steps']['users']['created'])->toBe(1);
});

it('refuses a step it does not know', function () {
    $this->artisan('legacy:import', ['--only' => 'everything', '--dry-run' => true]);
})->throws(InvalidArgumentException::class);
