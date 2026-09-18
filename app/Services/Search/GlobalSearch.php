<?php

namespace App\Services\Search;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * One box, and everything the person in front of it is allowed to find.
 *
 * Scoped by who is asking rather than by where they typed it: an administrator
 * searches the business, a client searches their own account, a student
 * searches their own courses. That is what lets the same box sit in the header
 * of all three panels without a single check about which panel it is in — and
 * it means a client can never turn up in a result list they should not see,
 * whatever the URL said.
 *
 * Wildcards in what somebody typed are escaped before the term reaches a LIKE,
 * so a percent sign is a percent sign rather than "match everything".
 */
class GlobalSearch
{
    /** Per group, so one very common word cannot fill the whole list. */
    public const PER_GROUP = 5;

    /** @return array<int, array<string, mixed>> */
    public function for(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $groups = match (true) {
            $user->isAdmin() => $this->forAdmin($user, $term),
            $user->isClient() => $this->forClient($user, $term),
            default => $this->forStudent($user, $term),
        };

        return collect($groups)->filter(fn (array $group) => $group['results'] !== [])->values()->all();
    }

    /* ------------------------------------------------------------- admin */

    protected function forAdmin(User $user, string $term): array
    {
        $groups = [];

        if ($user->hasPermission('clients.view')) {
            $groups[] = $this->group('Clients', $this->people($term, Role::Client, '/admin/clients/'));
        }

        if ($user->hasPermission('students.view')) {
            $groups[] = $this->group('Students', $this->people($term, Role::Student, '/admin/students/'));
        }

        if ($user->hasPermission('leads.view')) {
            $groups[] = $this->group('Leads', $this->search(
                Lead::query()->where(fn (Builder $q) => $this->match($q, $term, ['name', 'email', 'company', 'college_name'])),
                fn (Lead $lead) => [
                    'title' => $lead->name,
                    'subtitle' => collect([$lead->company ?: $lead->college_name, $lead->email])->filter()->join(' · '),
                    'href' => '/admin/leads/'.$lead->id,
                ],
            ));
        }

        if ($user->hasPermission('projects.view')) {
            $groups[] = $this->group('Projects', $this->search(
                Project::query()
                    ->with('client:id,name')
                    ->where(fn (Builder $q) => $this->match($q, $term, ['name', 'code', 'summary'])),
                fn (Project $project) => [
                    'title' => $project->name,
                    'subtitle' => collect([$project->client?->name, $project->statusLabel()])->filter()->join(' · '),
                    'href' => '/admin/projects/'.$project->id,
                ],
            ));
        }

        if ($user->hasPermission('billing.view')) {
            $groups[] = $this->group('Invoices', $this->search(
                Invoice::query()
                    ->with('user:id,name')
                    ->where(fn (Builder $q) => $this->match($q, $term, ['number'])),
                fn (Invoice $invoice) => [
                    'title' => $invoice->number,
                    'subtitle' => collect([$invoice->user?->name, $invoice->totalLabel()])->filter()->join(' · '),
                    'href' => $invoice->payment_request_id
                        ? '/admin/billing/'.$invoice->payment_request_id
                        : '/admin/invoices',
                ],
            ));
        }

        if ($user->hasPermission('support.manage')) {
            $groups[] = $this->group('Tickets', $this->search(
                SupportTicket::query()->where(fn (Builder $q) => $this->match($q, $term, ['reference', 'subject'])),
                fn (SupportTicket $ticket) => [
                    'title' => $ticket->subject,
                    'subtitle' => $ticket->reference,
                    'href' => '/admin/tickets/'.$ticket->id,
                ],
            ));
        }

        if ($user->hasPermission('catalogue.view')) {
            $groups[] = $this->group('Courses', $this->search(
                Course::query()->where(fn (Builder $q) => $this->match($q, $term, ['title', 'tagline', 'summary'])),
                fn (Course $course) => [
                    'title' => $course->title,
                    'subtitle' => str($course->type)->headline()->toString(),
                    'href' => '/admin/courses/'.$course->id.'/builder',
                ],
            ));

            $groups[] = $this->group('Batches', $this->search(
                Batch::query()
                    ->with('course:id,title')
                    ->where(fn (Builder $q) => $this->match($q, $term, ['name', 'code'])),
                fn (Batch $batch) => [
                    'title' => $batch->name,
                    'subtitle' => $batch->course?->title,
                    'href' => '/admin/batches/'.$batch->id.'/run',
                ],
            ));
        }

        return $groups;
    }

    /* ------------------------------------------------------------ client */

    protected function forClient(User $client, string $term): array
    {
        return [
            $this->group('Projects', $this->search(
                Project::query()
                    ->forClient($client)
                    ->where(fn (Builder $q) => $this->match($q, $term, ['name', 'summary'])),
                fn (Project $project) => [
                    'title' => $project->name,
                    'subtitle' => $project->statusLabel(),
                    'href' => '/client/projects/'.$project->id,
                ],
            )),
            $this->group('Invoices', $this->search(
                Invoice::query()
                    ->forUser($client)
                    ->where(fn (Builder $q) => $this->match($q, $term, ['number'])),
                fn (Invoice $invoice) => [
                    'title' => $invoice->number,
                    'subtitle' => $invoice->totalLabel(),
                    'href' => '/client/invoices/'.$invoice->id,
                ],
            )),
            $this->group('Tickets', $this->search(
                SupportTicket::query()
                    ->forClient($client)
                    ->where(fn (Builder $q) => $this->match($q, $term, ['reference', 'subject'])),
                fn (SupportTicket $ticket) => [
                    'title' => $ticket->subject,
                    'subtitle' => $ticket->reference,
                    'href' => '/client/tickets/'.$ticket->id,
                ],
            )),
        ];
    }

    /* ----------------------------------------------------------- student */

    protected function forStudent(User $student, string $term): array
    {
        $courseIds = $student->enrollments()->pluck('course_id');

        return [
            $this->group('My courses', $this->search(
                Course::query()
                    ->whereIn('id', $courseIds)
                    ->where(fn (Builder $q) => $this->match($q, $term, ['title', 'tagline', 'summary'])),
                fn (Course $course) => [
                    'title' => $course->title,
                    'subtitle' => str($course->type)->headline()->toString(),
                    'href' => '/student/courses/'.$course->id,
                ],
            )),
            $this->group('Lessons', $this->search(
                Lesson::query()
                    ->whereIn('course_id', $courseIds)
                    ->where('is_published', true)
                    ->with('course:id,title')
                    ->where(fn (Builder $q) => $this->match($q, $term, ['title', 'summary'])),
                fn (Lesson $lesson) => [
                    'title' => $lesson->title,
                    'subtitle' => $lesson->course?->title,
                    'href' => '/student/courses/'.$lesson->course_id.'/lessons/'.$lesson->id,
                ],
            )),
        ];
    }

    /* ----------------------------------------------------------- helpers */

    protected function people(string $term, Role $role, string $prefix): array
    {
        return $this->search(
            User::query()
                ->role($role)
                ->where(fn (Builder $q) => $this->match($q, $term, ['name', 'email', 'mobile'])),
            fn (User $person) => [
                'title' => $person->name,
                'subtitle' => $person->email,
                'href' => $prefix.$person->id,
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function search(Builder $query, callable $shape): array
    {
        return $query->take(self::PER_GROUP)->get()->map($shape)->all();
    }

    /** @param  array<int, string>  $columns */
    protected function match(Builder $query, string $term, array $columns): Builder
    {
        // The wildcards a person typed are literal characters, not operators.
        $escaped = addcslashes($term, '%_\\');

        foreach ($columns as $index => $column) {
            $query->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', "%{$escaped}%");
        }

        return $query;
    }

    /** @return array<string, mixed> */
    protected function group(string $label, array $results): array
    {
        return ['label' => $label, 'results' => $results];
    }
}
