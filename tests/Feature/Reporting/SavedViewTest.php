<?php

use App\Enums\Role;
use App\Models\SavedView;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->colleague = User::factory()->create(['role' => Role::Admin]);
});

function saveView(User $user, array $overrides = [])
{
    return test()->actingAs($user)->post('/views', [
        'name' => 'Overdue invoices',
        'screen' => '/admin/invoices',
        'state' => ['filter' => ['status' => 'overdue'], 'sort' => '-due_on'],
        ...$overrides,
    ]);
}

it('saves the query rather than the rows', function () {
    saveView($this->owner)->assertRedirect();

    $view = SavedView::query()->sole();

    expect($view->state['filter']['status'])->toBe('overdue')
        ->and($view->href())->toBe('/admin/invoices?filter%5Bstatus%5D=overdue&sort=-due_on');
});

it('applies a view by sending somebody to the same query', function () {
    saveView($this->owner);

    $view = SavedView::query()->sole();

    $this->actingAs($this->owner)
        ->get("/views/{$view->id}")
        ->assertRedirect($view->href());

    expect($view->fresh()->times_used)->toBe(1);
});

it('keeps a private view private', function () {
    saveView($this->owner);

    $body = $this->actingAs($this->colleague)->getJson('/views?screen=/admin/invoices')->json();

    expect($body['views'])->toBeEmpty();
});

it('shows a shared view to the team, and lets only its owner remove it', function () {
    saveView($this->owner, ['is_shared' => true]);

    $view = SavedView::query()->sole();

    $body = $this->actingAs($this->colleague)->getJson('/views?screen=/admin/invoices')->json();

    expect($body['views'])->toHaveCount(1)
        ->and($body['views'][0]['mine'])->toBeFalse();

    $this->actingAs($this->colleague)->delete("/views/{$view->id}")->assertNotFound();

    expect(SavedView::query()->count())->toBe(1);

    $this->actingAs($this->owner)->delete("/views/{$view->id}")->assertRedirect();

    expect(SavedView::query()->count())->toBe(0);
});

it('refuses a screen that is not one of ours', function () {
    // A saved view is a filter set on one of our own lists. Anything that
    // looks like somewhere else is refused rather than stored and followed
    // later by whoever clicks it.
    saveView($this->owner, ['screen' => 'https://example.com/phish'])
        ->assertSessionHasErrors('screen');

    saveView($this->owner, ['screen' => '//example.com/phish'])
        ->assertSessionHasErrors('screen');

    expect(SavedView::query()->count())->toBe(0);
});

it('only offers views saved on the screen being looked at', function () {
    saveView($this->owner, ['is_shared' => true]);
    saveView($this->owner, ['name' => 'Big projects', 'screen' => '/admin/projects', 'is_shared' => true]);

    $body = $this->actingAs($this->owner)->getJson('/views?screen=/admin/projects')->json();

    expect($body['views'])->toHaveCount(1)
        ->and($body['views'][0]['name'])->toBe('Big projects');
});
