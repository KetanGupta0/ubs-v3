<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Lead;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What an administrator sees first.
 *
 * Built around what needs acting on today rather than vanity totals. An
 * unanswered enquiry from four days ago is more useful on this screen than a
 * cumulative count of everything ever.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('admin/Dashboard', [
            'metrics' => $this->metrics(),
            'attention' => $this->needsAttention(),
            'recentLeads' => Lead::query()
                ->with(['solution:id,title', 'service:id,title', 'course:id,title,type', 'assignee:id,name'])
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'reference' => $lead->reference,
                    'name' => $lead->name,
                    'subject' => $lead->subject(),
                    'status' => $lead->status,
                    'assignedTo' => $lead->assignee?->name,
                    'receivedAt' => $lead->created_at->diffForHumans(),
                    'waitingDays' => $lead->created_at->diffInDays(now()),
                ]),
            'upcomingBatches' => Batch::query()
                ->with('course:id,title,type')
                ->where('status', 'upcoming')
                ->whereNotNull('starts_on')
                ->where('starts_on', '>=', today())
                ->orderBy('starts_on')
                ->limit(5)
                ->get()
                ->map(fn (Batch $batch) => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'course' => $batch->course?->title,
                    'type' => $batch->course?->type,
                    'startsOn' => $batch->starts_on?->format('j M Y'),
                    'startsIn' => $batch->starts_on?->diffForHumans(),
                    'seatsLeft' => $batch->seatsLeft(),
                    'nearlyFull' => $batch->isNearlyFull(),
                ]),
            'recentActivity' => AuditLog::query()
                ->with('actor:id,name')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'subject' => $log->subjectName(),
                    'label' => $log->subject_label,
                    'actor' => $log->actor?->name ?? 'System',
                    'at' => $log->created_at->diffForHumans(),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function metrics(): array
    {
        return [
            'openLeads' => Lead::query()->open()->count(),
            'newLeadsThisWeek' => Lead::query()->where('created_at', '>=', now()->subWeek())->count(),
            'clients' => User::query()->role(Role::Client)->count(),
            'students' => User::query()->role(Role::Student)->count(),
            'publishedSolutions' => Solution::query()->published()->count(),
            'liveOfferings' => Course::query()->publiclyVisible()->count(),
            'upcomingBatches' => Batch::query()->where('status', 'upcoming')->count(),
        ];
    }

    /**
     * Things that are quietly going wrong.
     *
     * Each one is phrased as a problem with a link, not a number, because a
     * number on a dashboard rarely makes anybody do anything.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function needsAttention(): array
    {
        $items = [];

        $stale = Lead::query()
            ->where('status', 'new')
            ->where('created_at', '<', now()->subDays(2))
            ->count();

        if ($stale > 0) {
            $items[] = [
                'tone' => 'danger',
                'title' => $stale.' '.str('enquiry')->plural($stale).' unanswered for more than two days',
                'body' => 'We tell people we reply within one working day.',
                'href' => '/admin/leads?filter[status]=new',
                'action' => 'Open the inbox',
            ];
        }

        $unassigned = Lead::query()->open()->whereNull('assigned_to')->count();

        if ($unassigned > 0) {
            $items[] = [
                'tone' => 'warning',
                'title' => $unassigned.' open '.str('enquiry')->plural($unassigned).' with nobody assigned',
                'body' => 'An enquiry belonging to everyone belongs to no one.',
                'href' => '/admin/leads',
                'action' => 'Assign them',
            ];
        }

        $neverSignedIn = User::query()
            ->whereIn('role', [Role::Client->value, Role::Student->value])
            ->whereNull('last_login_at')
            ->where('created_at', '<', now()->subDays(3))
            ->count();

        if ($neverSignedIn > 0) {
            $items[] = [
                'tone' => 'warning',
                'title' => $neverSignedIn.' '.str('account')->plural($neverSignedIn).' created but never signed in',
                'body' => 'The welcome message may not have reached them. You can resend the credentials.',
                'href' => '/admin/clients?filter[never_signed_in]=true',
                'action' => 'Review accounts',
            ];
        }

        $emptyBatches = Batch::query()
            ->where('status', 'upcoming')
            ->whereNotNull('starts_on')
            ->whereBetween('starts_on', [today(), today()->addWeeks(2)])
            ->where('seats_taken', '<', 3)
            ->count();

        if ($emptyBatches > 0) {
            $items[] = [
                'tone' => 'warning',
                'title' => $emptyBatches.' '.str('batch')->plural($emptyBatches).' starting soon with almost nobody enrolled',
                'body' => 'Worth deciding now whether to promote it or move the date.',
                'href' => '/admin/batches',
                'action' => 'Review batches',
            ];
        }

        return $items;
    }
}
