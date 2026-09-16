<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The design system gallery.
 *
 * It is a working page, not a screenshot: the table below is a real server
 * driven table over real rows, so a regression in search, sorting, filtering
 * or export shows up here before it reaches a customer facing screen.
 */
class DesignController extends Controller
{
    public function __invoke(Request $request): Response|HttpResponse
    {
        $table = $this->demoTable();

        // The same route serves the download, so an export always reflects the
        // filters currently applied on screen.
        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('Design/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    protected function demoTable(): Table
    {
        return Table::for(User::query())
            ->searchable(['name', 'email'])
            ->defaultSort('-created_at')
            ->exportName('design-system-demo')
            ->columns([
                Column::make('name', 'Name')->sortable(),
                Column::make('email', 'Email')->sortable(),
                Column::make('status', 'Status'),
                Column::make('created_at', 'Registered')->sortable(),
            ])
            ->filters([
                Filter::select('verified', [
                    ['value' => 'yes', 'label' => 'Verified'],
                    ['value' => 'no', 'label' => 'Unverified'],
                ], 'Email status')
                    ->placeholder('Any')
                    ->using(fn ($query, $value) => $value === 'yes'
                        ? $query->whereNotNull('email_verified_at')
                        : $query->whereNull('email_verified_at')),

                Filter::dateRange('created_at', 'Registered between'),
            ])
            ->transform(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->email_verified_at ? 'Verified' : 'Unverified',
                'created_at' => $user->created_at?->format('d M Y'),
            ]);
    }
}
