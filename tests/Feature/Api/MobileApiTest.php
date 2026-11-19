<?php

use App\Enums\AuthEvent;
use App\Models\ActivityLog;
use App\Models\AuthAuditLog;
use App\Models\Permission;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\TicketRaised;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\ApiContract;

/*
 * Step I: what the app's API does beyond answering in the documented shape.
 * Signing in goes through the same pipeline as the web, a temporary password
 * is replaced before anything else opens, a device's token can be swapped and
 * any device signed out, and a ticket raised from the app reaches the desk.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->device = ['device_name' => 'Pixel 9', 'device_identifier' => 'pixel-abc', 'platform' => 'android', 'app_version' => '1.0.0'];

    // Each request stands alone, as an app's do.
    $this->as = function (?string $token) {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    };
});

it('signs in through the same pipeline as the web: the audit entry, the last sign in and a student\'s activity', function () {
    $student = User::factory()->student()->create(['email' => 'meera@example.com', 'password' => 'Password123!']);

    $this->postJson('/api/v1/auth/login', ['identifier' => 'meera@example.com', 'password' => 'Password123!', ...$this->device])->assertOk();

    expect(AuthAuditLog::query()->where('event', AuthEvent::LoginSucceeded->value)->where('user_id', $student->id)->value('method'))->toBe('api.password')
        ->and($student->fresh()->last_login_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('user_id', $student->id)->where('action', 'signed.in')->exists())->toBeTrue();
});

it('opens nothing but the way through until a temporary password is replaced, then signs the other devices out', function () {
    $user = User::factory()->client()->create(['email' => 'priya@example.com', 'password' => 'Temporary-123', 'must_change_password' => true]);
    $elsewhere = $user->createToken('Old laptop', ['*'])->plainTextToken;

    $token = $this->postJson('/api/v1/auth/login', ['identifier' => 'priya@example.com', 'password' => 'Temporary-123', ...$this->device])
        ->assertOk()->assertJsonPath('must_change_password', true)->json('token');

    ($this->as)($token)->getJson('/api/v1/client/overview')->assertStatus(423)->assertJsonPath('code', 'password_change_required');
    ($this->as)($token)->getJson('/api/v1/me')->assertOk();

    ($this->as)($token)->postJson('/api/v1/auth/password', ['current_password' => 'Temporary-123', 'password' => 'Temporary-123', 'password_confirmation' => 'Temporary-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    ($this->as)($token)->postJson('/api/v1/auth/password', ['current_password' => 'Temporary-123', 'password' => 'Chosen-pass-456', 'password_confirmation' => 'Chosen-pass-456'])
        ->assertOk()->assertJsonPath('data.email', 'priya@example.com');

    expect($user->fresh()->must_change_password)->toBeFalse()
        ->and(AuthAuditLog::query()->where('event', AuthEvent::PasswordChanged->value)->where('user_id', $user->id)->exists())->toBeTrue();

    ($this->as)($token)->getJson('/api/v1/client/overview')->assertOk();
    ($this->as)($elsewhere)->getJson('/api/v1/me')->assertUnauthorized();
});

it('swaps a device\'s token for a new one, keeping its details, and the old one stops at once', function () {
    $user = User::factory()->student()->create(['email' => 'meera@example.com', 'password' => 'Password123!']);
    $old = $this->postJson('/api/v1/auth/login', ['identifier' => 'meera@example.com', 'password' => 'Password123!', ...$this->device])->json('token');
    ($this->as)($old)->postJson('/api/v1/devices/push-token', ['push_token' => 'fcm-123'])->assertOk();

    $new = ($this->as)($old)->postJson('/api/v1/auth/refresh')->assertOk()->json('token');

    ($this->as)($old)->getJson('/api/v1/me')->assertUnauthorized();
    ($this->as)($new)->getJson('/api/v1/me')->assertOk();

    $token = PersonalAccessToken::findToken($new);
    expect($token->only(['name', 'device_identifier', 'platform', 'app_version', 'push_token']))
        ->toBe(['name' => 'Pixel 9', 'device_identifier' => 'pixel-abc', 'platform' => 'android', 'app_version' => '1.0.0', 'push_token' => 'fcm-123'])
        ->and($user->tokens()->count())->toBe(1);
});

it('lists the account\'s devices and signs one out, never somebody else\'s', function () {
    $user = User::factory()->student()->create();
    $phone = $user->createToken('Phone', ['*'])->plainTextToken;
    $tablet = $user->createToken('Tablet', ['*']);
    $user->createToken('Phone (challenge)', ['two-factor']);
    $stranger = User::factory()->student()->create()->createToken('Theirs', ['*']);

    ($this->as)($phone)->getJson('/api/v1/devices')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data', fn ($devices) => collect($devices)->where('current', true)->pluck('name')->all() === ['Phone']);

    ($this->as)($phone)->deleteJson("/api/v1/devices/{$stranger->accessToken->id}")->assertNotFound();
    ($this->as)($phone)->deleteJson("/api/v1/devices/{$tablet->accessToken->id}")->assertOk();

    ($this->as)($tablet->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    expect(PersonalAccessToken::query()->whereKey($stranger->accessToken->id)->exists())->toBeTrue();
});

it('takes only a challenge token at the two-step step, never a full one', function () {
    $user = User::factory()->student()->create();

    ($this->as)($user->createToken('Phone', ['*'])->plainTextToken)
        ->postJson('/api/v1/auth/two-factor', ['code' => '123456', ...$this->device])
        ->assertForbidden();
});

it('tells the support desk about a ticket raised from the app, as from the portal', function () {
    Notification::fake();
    $client = User::factory()->client()->create();
    $desk = User::factory()->admin()->create();
    $desk->permissions()->attach(Permission::query()->where('key', 'support.manage')->sole());
    $owner = User::factory()->owner()->create();
    $bystander = User::factory()->admin()->create();

    ($this->as)($client->createToken('Phone', ['*'])->plainTextToken)
        ->postJson('/api/v1/client/tickets', ['subject' => 'Invoices page is blank', 'body' => 'Since the update.', 'priority' => 'high', 'category' => 'bug'])
        ->assertCreated();

    $ticket = SupportTicket::query()->sole();
    expect($ticket->requester_id)->toBe($client->id)->and($ticket->category)->toBe('bug');
    Notification::assertSentTo([$desk, $owner], TicketRaised::class);
    Notification::assertNotSentTo($bystander, TicketRaised::class);
});

it('holds the server to the document: an undocumented field, or a missing one, fails', function () {
    $user = ['id' => 1, 'name' => 'Meera', 'email' => 'meera@example.com', 'mobile' => null, 'role' => 'student', 'status' => 'active',
        'avatar_url' => null, 'email_verified' => true, 'mobile_verified' => false, 'two_factor_enabled' => false];
    $answer = fn (array $data) => TestResponse::fromBaseResponse(response()->json(['data' => $data]));

    ApiContract::assertMatches($answer($user), 'GET', '/me');

    expect(fn () => ApiContract::assertMatches($answer([...$user, 'surprise' => 1]), 'GET', '/me'))->toThrow(AssertionFailedError::class)
        ->and(fn () => ApiContract::assertMatches($answer(collect($user)->except('role')->all()), 'GET', '/me'))->toThrow(AssertionFailedError::class)
        ->and(fn () => ApiContract::assertMatches($answer([...$user, 'role' => 'wizard']), 'GET', '/me'))->toThrow(AssertionFailedError::class);
});
