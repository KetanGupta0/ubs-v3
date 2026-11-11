<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * Every link in every menu opens.
 *
 * Read straight out of resources/js/support/navigation.js, so a menu entry
 * added there is tested without anybody remembering to list it here. A link
 * nobody clicked in a test is a link nobody tested: in Phase 5 every "Edit"
 * link in the admin catalogue turned out to have been a 404 since Phase 3.
 */
function menuFor(string $role): array
{
    $source = file_get_contents(resource_path('js/support/navigation.js'));
    preg_match('/^    '.$role.': \[(.*?)^    \],/ms', $source, $block);

    preg_match_all('/^\s*\{(.*?)\},?\s*$/m', $block[1] ?? '', $lines);

    return collect($lines[1])->map(function (string $line) {
        preg_match("/href: '([^']+)'/", $line, $href);
        preg_match("/group: '([^']+)'/", $line, $group);
        preg_match("/label: '([^']+)'/", $line, $label);

        return ['href' => $href[1] ?? null, 'group' => $group[1] ?? null, 'label' => $label[1] ?? null];
    })->all();
}

beforeEach(fn () => $this->seed(PermissionSeeder::class));

it('opens every link in the admin menu', function () {
    $menu = menuFor('admin');
    $owner = User::factory()->owner()->create();

    expect(count($menu))->toBeGreaterThan(20);

    foreach ($menu as $item) {
        expect($item['href'])->not->toBeNull("{$item['label']} has no link");
        $this->actingAs($owner)->get($item['href'])->assertOk();
    }
});

it('puts every admin menu entry in a group, each group in one run', function () {
    $groups = collect(menuFor('admin'))->pluck('group');

    expect($groups->filter()->count())->toBe($groups->count());

    // A group split in two would show its heading twice.
    $runs = $groups->reduce(fn (array $runs, string $group) => end($runs) === $group ? $runs : [...$runs, $group], []);
    expect($runs)->toBe(array_values(array_unique($runs)));
});

it('opens every link in the client and student menus', function (string $role) {
    $user = User::factory()->{$role}()->create();

    foreach (menuFor($role) as $item) {
        $this->actingAs($user)->get($item['href'])->assertOk();
    }
})->with(['client', 'student']);
