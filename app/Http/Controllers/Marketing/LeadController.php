<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lead;
use App\Models\Service;
use App\Models\Solution;
use App\Models\User;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\LeadReceived;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Enquiries from every form on the public site.
 *
 * The context matters as much as the message. An enquiry that records which
 * solution page it came from lets whoever answers it open the conversation
 * knowing what the person was reading, instead of asking them to explain again.
 */
class LeadController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile')
                ? Identifier::normaliseMobile((string) $request->input('mobile'))
                : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'company' => ['nullable', 'string', 'max:160'],
            'college_name' => ['nullable', 'string', 'max:160'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],

            'interest' => ['required', Rule::in(['solution', 'service', 'training', 'internship', 'college', 'general'])],
            'solution_slug' => ['nullable', 'string', 'exists:solutions,slug'],
            'service_slug' => ['nullable', 'string', 'exists:services,slug'],
            'course_slug' => ['nullable', 'string', 'exists:courses,slug'],

            'budget_band' => ['nullable', 'string', 'max:40'],
            'timeline' => ['nullable', 'string', 'max:40'],
            'source_page' => ['nullable', 'string', 'max:255'],

            // A field a person never sees and a crude bot usually fills.
            'website' => ['prohibited'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
            'message.min' => 'Tell us a little more so we can reply usefully.',
            'website.prohibited' => 'That submission could not be accepted.',
        ]);

        $lead = Lead::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'company' => $validated['company'] ?? null,
            'college_name' => $validated['college_name'] ?? null,
            'student_count' => $validated['student_count'] ?? null,
            'message' => $validated['message'],
            'interest' => $validated['interest'],
            'solution_id' => $this->idFor(Solution::class, $validated['solution_slug'] ?? null),
            'service_id' => $this->idFor(Service::class, $validated['service_slug'] ?? null),
            'course_id' => $this->idFor(Course::class, $validated['course_slug'] ?? null),
            'budget_band' => $validated['budget_band'] ?? null,
            'timeline' => $validated['timeline'] ?? null,
            'source_page' => $validated['source_page'] ?? $request->headers->get('referer'),
            'referrer' => $request->headers->get('referer'),
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
        ]);

        $this->notify($lead);

        return back()->with([
            'success' => 'Thanks. We have your enquiry and will reply within one working day.',
            'leadReference' => $lead->reference,
        ]);
    }

    protected function idFor(string $model, ?string $slug): ?int
    {
        return $slug ? $model::query()->where('slug', $slug)->value('id') : null;
    }

    /**
     * Tell the team, and confirm to the sender.
     *
     * Delivery failures must not lose the enquiry, which is already saved by the
     * time this runs, so a broken mail or SMS provider costs a notification
     * rather than a customer.
     */
    protected function notify(Lead $lead): void
    {
        $lead->loadMissing(['solution', 'service', 'course']);

        try {
            $admins = User::query()->role(Role::Admin)->active()->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new LeadReceived($lead));
            }

            Notification::route('mail', $lead->email)->notify(new LeadAcknowledgement($lead));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
