<?php

use App\Models\Permission;
use App\Models\User;

it('lets each role into its own dashboard', function () {
    $cases = [
        [User::factory()->admin()->create(), '/admin'],
        [User::factory()->client()->create(), '/client'],
        [User::factory()->student()->create(), '/student'],
    ];

    foreach ($cases as [$user, $path]) {
        $this->actingAs($user)->get($path)->assertOk();
    }
});

it('redirects someone to their own dashboard instead of showing a wall', function () {
    $student = User::factory()->student()->create();

    // A stale bookmark is far more likely than an attack, so a redirect beats
    // a 403 here.
    $this->actingAs($student)->get('/admin')->assertRedirect('/student');
    $this->actingAs($student)->get('/client')->assertRedirect('/student');
});

it('sends a guest to the sign in form', function () {
    foreach (['/admin', '/client', '/student', '/settings/security'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});

it('grants the owner every permission implicitly', function () {
    $owner = User::factory()->owner()->create();
    $student = User::factory()->student()->create();

    expect($owner->hasPermission('billing.manage'))->toBeTrue()
        ->and($student->hasPermission('billing.manage'))->toBeFalse();
});

it('does not hand a staff administrator everything just for being an administrator', function () {
    // The whole point of the permission list. A staff account created to run
    // the leads inbox must not also be able to change the company's billing.
    $staff = User::factory()->admin()->create();

    expect($staff->hasPermission('billing.manage'))->toBeFalse();
});

it('grants a specific permission to a non administrator', function () {
    $staff = User::factory()->client()->create();
    $permission = Permission::query()->create([
        'key' => 'leads.view',
        'label' => 'View the leads inbox',
        'group' => 'Leads',
    ]);

    $staff->permissions()->attach($permission);

    expect($staff->fresh()->hasPermission('leads.view'))->toBeTrue()
        ->and($staff->fresh()->hasPermission('leads.assign'))->toBeFalse();
});
