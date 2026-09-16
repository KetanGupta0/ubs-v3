<?php

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

function grantPermission(User $user, string $key): User
{
    $user->permissions()->attach(Permission::query()->where('key', $key)->sole());

    return $user->fresh();
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('lets the owner everywhere', function () {
    $owner = User::factory()->owner()->create();

    foreach (['/admin', '/admin/leads', '/admin/clients', '/admin/settings', '/admin/audit-log'] as $path) {
        $this->actingAs($owner)->get($path)->assertOk();
    }
});

it('keeps a staff account out of what it was not granted', function () {
    $staff = grantPermission(User::factory()->admin()->create(), 'leads.view');

    $this->actingAs($staff)->get('/admin/leads')->assertOk();
    $this->actingAs($staff)->get('/admin/settings')->assertForbidden();
    $this->actingAs($staff)->get('/admin/audit-log')->assertForbidden();
});

it('still lets a staff account with nothing granted reach its dashboard', function () {
    // An account that cannot get in at all is harder to reason about than one
    // that gets in and can do nothing.
    $staff = User::factory()->admin()->create();

    $this->actingAs($staff)->get('/admin')->assertOk();
    $this->actingAs($staff)->get('/admin/clients')->assertForbidden();
});

it('will not let somebody widen their own permissions', function () {
    $staff = grantPermission(User::factory()->admin()->create(), 'staff.manage');

    $this->actingAs($staff)
        ->put("/admin/staff/{$staff->id}/permissions", ['permissions' => ['settings.manage', 'billing.refund']])
        ->assertSessionHasErrors('permissions');

    expect($staff->fresh()->hasPermission('settings.manage'))->toBeFalse();
});

it('will not restrict or suspend the owner', function () {
    $owner = User::factory()->owner()->create();
    $staff = grantPermission(User::factory()->admin()->create(), 'staff.manage');

    $this->actingAs($staff)
        ->put("/admin/staff/{$owner->id}/permissions", ['permissions' => []])
        ->assertSessionHasErrors('permissions');

    $this->actingAs($staff)
        ->put("/admin/staff/{$owner->id}/status", ['status' => 'suspended'])
        ->assertSessionHasErrors('status');

    expect($owner->fresh()->canSignIn())->toBeTrue();
});

it('records a permission change in the audit log', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->admin()->create();

    $this->actingAs($owner)->put("/admin/staff/{$staff->id}/permissions", [
        'permissions' => ['leads.view'],
    ])->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'staff.permissions_changed',
        'subject_id' => $staff->id,
    ]);
});

it('cuts off a suspended staff account at its tokens', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->admin()->create();
    $staff->createToken('phone', ['*']);

    $this->actingAs($owner)->put("/admin/staff/{$staff->id}/status", ['status' => 'suspended']);

    expect($staff->fresh()->tokens()->count())->toBe(0);
});

it('does not send a client or student any admin permissions', function () {
    $client = User::factory()->client()->create();

    $this->actingAs($client)->get('/client')
        ->assertInertia(fn ($page) => $page->where('auth.permissions', []));
});
