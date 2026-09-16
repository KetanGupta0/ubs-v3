<?php

use App\Models\User;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;

/**
 * The table pipeline is shared by every dashboard list, so a regression here
 * would be a regression everywhere. These cover the behaviour users rely on
 * and the whitelisting that stops a hand edited query string reaching columns
 * the screen never offered.
 */
function demoTable(): Table
{
    return Table::for(User::query())
        ->searchable(['name', 'email'])
        ->defaultSort('-id')
        ->columns([
            Column::make('name')->sortable(),
            Column::make('email')->sortable(),
            Column::make('created_at')->sortable(),
        ])
        ->filters([
            Filter::text('name'),
            Filter::dateRange('created_at'),
            Filter::select('status', ['active', 'archived'])
                ->using(fn ($query, $value) => $value === 'active'
                    ? $query->whereNotNull('email_verified_at')
                    : $query->whereNull('email_verified_at')),
        ]);
}

function tableFor(array $query = []): array
{
    return demoTable()->toArray(Request::create('/things', 'GET', $query));
}

beforeEach(function () {
    User::factory()->create(['name' => 'Aarti Sharma', 'email' => 'aarti@example.com', 'email_verified_at' => now()]);
    User::factory()->create(['name' => 'Rahul Verma', 'email' => 'rahul@example.com', 'email_verified_at' => null]);
    User::factory()->create(['name' => 'Priya Nair', 'email' => 'priya@example.com', 'email_verified_at' => now()]);
});

it('returns every row when nothing is filtered', function () {
    expect(tableFor()['meta']['total'])->toBe(3);
});

it('searches across every declared field', function () {
    expect(tableFor(['q' => 'aarti'])['meta']['total'])->toBe(1)
        ->and(tableFor(['q' => 'rahul@example.com'])['meta']['total'])->toBe(1);
});

it('treats a wildcard in the search term as a literal character', function () {
    // Without escaping, '%' would match every row.
    expect(tableFor(['q' => '%'])['meta']['total'])->toBe(0)
        ->and(tableFor(['q' => '_'])['meta']['total'])->toBe(0);
});

it('sorts ascending and descending on a whitelisted column', function () {
    $ascending = tableFor(['sort' => 'name'])['rows'];
    $descending = tableFor(['sort' => '-name'])['rows'];

    expect($ascending[0]->name)->toBe('Aarti Sharma')
        ->and($descending[0]->name)->toBe('Rahul Verma');
});

it('ignores a sort column that was never offered', function () {
    // 'password' is a real column but is not sortable, so it must not reach SQL.
    $rows = tableFor(['sort' => 'password'])['rows'];

    expect($rows)->toHaveCount(3);
});

it('applies a filter that declares its own clause', function () {
    expect(tableFor(['filter' => ['status' => 'active']])['meta']['total'])->toBe(2)
        ->and(tableFor(['filter' => ['status' => 'archived']])['meta']['total'])->toBe(1);
});

it('ignores a filter key that was never declared', function () {
    expect(tableFor(['filter' => ['password' => 'secret']])['meta']['total'])->toBe(3);
});

it('treats an empty filter value as no filter at all', function () {
    expect(tableFor(['filter' => ['name' => '']])['meta']['total'])->toBe(3)
        ->and(tableFor(['filter' => ['created_at' => ['from' => '', 'to' => '']]])['meta']['total'])->toBe(3);
});

it('honours both ends of a date range', function () {
    User::query()->where('name', 'Aarti Sharma')->update(['created_at' => now()->subYear()]);

    $from = now()->subMonth()->toDateString();

    expect(tableFor(['filter' => ['created_at' => ['from' => $from]]])['meta']['total'])->toBe(2)
        ->and(tableFor(['filter' => ['created_at' => ['to' => $from]]])['meta']['total'])->toBe(1);
});

it('only accepts a per page value it offered', function () {
    expect(tableFor(['per_page' => 10])['state']['per_page'])->toBe(10)
        ->and(tableFor(['per_page' => 9999])['state']['per_page'])->toBe(25);
});

it('describes its columns and filters to the client', function () {
    $table = tableFor();

    expect(collect($table['columns'])->pluck('key')->all())->toBe(['name', 'email', 'created_at'])
        ->and(collect($table['filters'])->pluck('key')->all())->toBe(['name', 'created_at', 'status']);
});
