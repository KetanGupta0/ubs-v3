<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\LegalDocument;
use App\Models\MailSuppression;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a greeting or an announcement is for (§8), worked out from filters, one
 * address once.
 *
 * Staff are never in it. Somebody who only sent an enquiry is in it only once
 * the privacy policy saying so is published and the admin has switched it on
 * (X19). Course, batch, college and enrolment narrow the students; joining
 * dates and never having signed in narrow account holders; the subject of an
 * enquiry narrows enquiry senders.
 */
class Audience
{
    public const GROUPS = [
        'students' => 'Students',
        'clients' => 'Clients and their people',
        'enquiries' => 'People who sent an enquiry',
    ];

    public const SETTING = 'mail.include_enquiries';

    /**
     * Filters as they are stored on a send, from whatever was posted.
     *
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $int = fn ($value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null;

        return [
            'groups' => array_values(array_intersect(array_keys(self::GROUPS), (array) ($input['groups'] ?? []))),
            'course_id' => $int($input['course_id'] ?? null),
            'batch_id' => $int($input['batch_id'] ?? null),
            'college_id' => $int($input['college_id'] ?? null),
            'enrolment_status' => in_array($input['enrolment_status'] ?? null, ['active', 'completed', 'dropped', 'on_hold'], true) ? $input['enrolment_status'] : null,
            'lead_interest' => is_string($input['lead_interest'] ?? null) && $input['lead_interest'] !== '' ? $input['lead_interest'] : null,
            'joined_from' => ($input['joined_from'] ?? null) ?: null,
            'joined_to' => ($input['joined_to'] ?? null) ?: null,
            'never_signed_in' => filter_var($input['never_signed_in'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Whether enquiry senders may be mailed at all: the admin's switch, behind the policy. */
    public static function enquiriesAllowed(): bool
    {
        return (bool) Setting::get(self::SETTING, false) && self::policyAllows();
    }

    /** The privacy policy version that says we send these is published (version 2 or later). */
    public static function policyAllows(): bool
    {
        $document = LegalDocument::query()->where('key', 'privacy')->first();

        return (int) $document?->versions()->where('status', 'published')->max('version') >= 2;
    }

    /**
     * Everybody it reaches, before the do-not-mail list.
     *
     * @return Collection<int, array{email: string, name: ?string, user_id: ?int, lead_id: ?int}>
     */
    public function recipients(array $filters): Collection
    {
        $filters = self::normalise($filters);
        $groups = $filters['groups'];
        $people = collect();

        $roles = array_values(array_filter([
            in_array('students', $groups, true) ? Role::Student->value : null,
            in_array('clients', $groups, true) ? Role::Client->value : null,
        ]));

        if ($roles !== []) {
            User::query()
                ->active()
                ->whereIn('role', $roles)
                ->whereNotNull('email')
                ->when($filters['joined_from'], fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                ->when($filters['joined_to'], fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))
                ->when($filters['never_signed_in'], fn (Builder $q) => $q->whereNull('last_login_at'))
                ->where(fn (Builder $q) => $q
                    ->where('role', '!=', Role::Student->value)
                    ->orWhere(fn (Builder $students) => $this->narrowStudents($students, $filters)))
                ->orderBy('id')
                ->get(['id', 'name', 'email'])
                ->each(fn (User $user) => $people->push(['email' => mb_strtolower($user->email), 'name' => $user->name, 'user_id' => $user->id, 'lead_id' => null]));
        }

        if (in_array('enquiries', $groups, true) && self::enquiriesAllowed()) {
            // An enquiry sender with an account is reached as that account, or not at all.
            $accounts = User::query()->pluck('email')->map(fn ($email) => mb_strtolower((string) $email))->flip();

            Lead::query()
                ->when($filters['lead_interest'], fn (Builder $q, string $interest) => $q->where('interest', $interest))
                ->when($filters['joined_from'], fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                ->when($filters['joined_to'], fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))
                ->orderByDesc('id')
                ->get(['id', 'name', 'email'])
                ->reject(fn (Lead $lead) => $accounts->has(mb_strtolower($lead->email)))
                ->each(fn (Lead $lead) => $people->push(['email' => mb_strtolower($lead->email), 'name' => $lead->name, 'user_id' => null, 'lead_id' => $lead->id]));
        }

        return $people->filter(fn (array $person) => filter_var($person['email'], FILTER_VALIDATE_EMAIL))->unique('email')->values();
    }

    /** @return array{total: int, suppressed: int, reachable: int} The count shown before sending. */
    public function count(array $filters): array
    {
        $emails = $this->recipients($filters)->pluck('email');
        $suppressed = $emails->isEmpty() ? 0 : MailSuppression::query()->whereIn('email', $emails)->count();

        return ['total' => $emails->count(), 'suppressed' => $suppressed, 'reachable' => $emails->count() - $suppressed];
    }

    protected function narrowStudents(Builder $query, array $filters): Builder
    {
        $query->where('role', Role::Student->value);

        if ($filters['course_id'] || $filters['batch_id'] || $filters['enrolment_status']) {
            $query->whereHas('enrollments', fn (Builder $enrolment) => $enrolment
                ->when($filters['course_id'], fn (Builder $q, int $id) => $q->where('course_id', $id))
                ->when($filters['batch_id'], fn (Builder $q, int $id) => $q->where('batch_id', $id))
                ->when($filters['enrolment_status'], fn (Builder $q, string $status) => $q->where('status', $status)));
        }

        if ($filters['college_id']) {
            $query->whereHas('profile', fn (Builder $profile) => $profile->where('college_id', $filters['college_id']));
        }

        return $query;
    }
}
