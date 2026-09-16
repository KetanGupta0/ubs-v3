<?php

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\Admin\Auditor;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

it('records who changed what, as before and after', function () {
    $lead = Lead::query()->create([
        'name' => 'Priya Nair',
        'email' => 'priya@example.test',
        'status' => 'new',
    ]);

    $this->actingAs($this->owner)->put("/admin/leads/{$lead->id}", [
        'status' => 'qualified',
    ])->assertRedirect();

    $entry = AuditLog::query()->latest('id')->first();

    expect($entry->actor_id)->toBe($this->owner->id)
        ->and($entry->changes['status'])->toBe(['from' => 'new', 'to' => 'qualified']);
});

it('never writes a secret into the log', function () {
    $user = User::factory()->client()->create();
    $user->fill(['password' => 'a-new-password', 'name' => 'Renamed']);

    app(Auditor::class)->updated($user);

    $entry = AuditLog::query()->latest('id')->first();

    expect($entry->changes['password'])->toBe(['from' => '••••', 'to' => '••••'])
        ->and(json_encode($entry->changes))->not->toContain('a-new-password');
});

it('writes nothing when nothing actually moved', function () {
    $user = User::factory()->client()->create();
    $before = AuditLog::query()->count();

    app(Auditor::class)->updated($user);

    expect(AuditLog::query()->count())->toBe($before);
});

it('keeps a long value from filling the log', function () {
    $user = User::factory()->client()->create();
    $user->name = str_repeat('a', 900);

    app(Auditor::class)->updated($user);

    expect(mb_strlen(AuditLog::query()->latest('id')->first()->changes['name']['to']))
        ->toBeLessThanOrEqual(303);
});

it('is read only from inside the application', function () {
    $entry = AuditLog::query()->create([
        'actor_id' => $this->owner->id,
        'action' => 'test.entry',
    ]);

    // There is no route to change or remove an entry, which is the point: the
    // first thing anybody covering their tracks would do is edit the log.
    $this->actingAs($this->owner)->put("/admin/audit-log/{$entry->id}")->assertNotFound();
    $this->actingAs($this->owner)->delete("/admin/audit-log/{$entry->id}")->assertNotFound();
});
