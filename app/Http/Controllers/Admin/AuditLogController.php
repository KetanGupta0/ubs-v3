<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Who changed what.
 *
 * Read only by design. An audit log that can be edited from inside the
 * application answers nothing, because the first thing anyone covering their
 * tracks would do is edit it.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/AuditLog', [
            'table' => $table->toArray($request),
        ]);
    }

    protected function table(): Table
    {
        return Table::for(AuditLog::query()->with('actor:id,name'))
            ->searchable(['action', 'subject_label', 'subject_type'])
            ->defaultSort('-created_at')
            ->exportName('audit-log')
            ->columns([
                Column::make('created_at', 'When')->sortable(),
                Column::make('actor', 'Who'),
                Column::make('action', 'Action')->sortable(),
                Column::make('subject', 'Record'),
                Column::make('label', 'Which one'),
                Column::make('summary', 'What changed'),
                Column::make('ip_address', 'From')->hidden(),
            ])
            ->filters([
                Filter::text('action', 'Action'),
                Filter::select('actor_id', User::query()->role(Role::Admin)->orderBy('name')->get()
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(), 'Who')
                    ->placeholder('Anyone'),
                Filter::dateRange('created_at', 'Between'),
            ])
            ->transform(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->format('j M Y, g:i a'),
                'actor' => $log->actor?->name ?? 'System',
                'action' => $log->action,
                'subject' => $log->subjectName() ?? '—',
                'label' => $log->subject_label ?? '—',
                'summary' => $this->summarise($log),
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
            ]);
    }

    /** A one line version of the change, with the detail available on expand. */
    protected function summarise(AuditLog $log): string
    {
        $changes = $log->changes ?? [];

        if ($changes === []) {
            return '—';
        }

        $fields = collect($changes)
            ->keys()
            ->map(fn (string $key) => str($key)->headline()->lower()->toString());

        return $fields->count() <= 3
            ? $fields->join(', ', ' and ')
            : $fields->take(2)->join(', ').' and '.($fields->count() - 2).' more';
    }
}
