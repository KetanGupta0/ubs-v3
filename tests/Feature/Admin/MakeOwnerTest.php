<?php

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;

/*
 * php artisan app:owner: the one way the owner is named, from the shell, at
 * install and at cutover, when the administrators brought across from v2 hold
 * no permissions and nobody can grant any.
 */

it('makes an administrator the owner and records it', function () {
    $admin = User::factory()->admin()->create(['email' => 'office@example.com']);

    $this->artisan('app:owner', ['email' => '  Office@Example.com '])
        ->expectsOutputToContain('office@example.com is now the owner.')
        ->assertSuccessful();

    expect($admin->fresh()->is_owner)->toBeTrue()
        ->and($admin->fresh()->hasPermission('people.view_as'))->toBeTrue();

    $entry = AuditLog::query()->where('subject_id', $admin->id)->sole();
    expect($entry->action)->toBe('updated')
        ->and((bool) $entry->changes['is_owner']['from'])->toBeFalse()
        ->and((bool) $entry->changes['is_owner']['to'])->toBeTrue();
});

it('refuses anybody who is not an administrator, and an address nobody has', function () {
    $student = User::factory()->student()->create(['email' => 'learner@example.com']);
    $client = User::factory()->client()->create(['email' => 'buyer@example.com']);

    foreach (['learner@example.com', 'buyer@example.com', 'nobody@example.com'] as $email) {
        $this->artisan('app:owner', ['email' => $email])
            ->expectsOutputToContain('No staff account has that address.')
            ->assertFailed();
    }

    expect($student->fresh()->is_owner)->toBeFalse()
        ->and($client->fresh()->is_owner)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a suspended administrator', function () {
    $admin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    $this->artisan('app:owner', ['email' => $admin->email])->assertFailed();

    expect($admin->fresh()->is_owner)->toBeFalse();
});

it('keeps one owner: a second needs --replace, which takes the flag from the first', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->artisan('app:owner', ['email' => $admin->email])
        ->expectsOutputToContain('There is already an owner')
        ->assertFailed();

    expect($admin->fresh()->is_owner)->toBeFalse()
        ->and($owner->fresh()->is_owner)->toBeTrue();

    $this->artisan('app:owner', ['email' => $admin->email, '--replace' => true])
        ->expectsOutputToContain("{$owner->email} is no longer the owner.")
        ->assertSuccessful();

    expect($admin->fresh()->is_owner)->toBeTrue()
        ->and($owner->fresh()->is_owner)->toBeFalse()
        ->and(User::query()->where('is_owner', true)->count())->toBe(1)
        ->and((bool) AuditLog::query()->where('subject_id', $owner->id)->sole()->changes['is_owner']['to'])->toBeFalse();
});

it('changes nothing for the account that is already the owner', function () {
    $owner = User::factory()->owner()->create();

    $this->artisan('app:owner', ['email' => $owner->email])
        ->expectsOutputToContain('is already the owner.')
        ->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(0);
});
