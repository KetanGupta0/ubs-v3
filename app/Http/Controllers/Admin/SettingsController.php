<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateTest;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Services\Admin\Auditor;
use App\Support\HumanCheck;
use App\Support\MessageCatalogue;
use App\Support\SiteIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company details and the wording of automated messages.
 *
 * Provider credentials are deliberately not editable here. Keys belong in the
 * environment, where they are not in a database backup and not one mistaken
 * click from being displayed on a screen. This page shows whether each provider
 * is configured, and nothing more.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'company' => [
                'name' => Setting::get('company.name', config('company.name')),
                'legal_name' => Setting::get('company.legal_name', config('company.legal_name')),
                'email' => Setting::get('company.email', config('company.email')),
                'phone' => Setting::get('company.phone', config('company.phone')),
                'address' => Setting::get('company.address', config('company.address')),
                'city' => Setting::get('company.city'),
                'state' => Setting::get('company.state'),
                'postal_code' => Setting::get('company.postal_code'),
                'gstin' => Setting::get('company.gstin', config('company.gstin')),
                'cin' => Setting::get('company.cin', config('company.cin')),
                'pan' => Setting::get('company.pan', config('company.pan')),
            ],
            'site' => SiteIdentity::settings(),
            'socialNetworks' => collect(SiteIdentity::SOCIAL)->map(fn ($network, $key) => ['key' => $key, 'label' => $network['label']])->values(),
            'invoicing' => [
                'prefix' => Setting::get('invoicing.prefix', 'UBS'),
                'next_number' => Setting::get('invoicing.next_number', 1),
                'financial_year_start_month' => Setting::get('invoicing.financial_year_start_month', 4),
                'default_tax_rate' => Setting::get('invoicing.default_tax_rate', 18),
                'terms' => Setting::get('invoicing.terms'),
            ],
            'templates' => $this->templates(),

            /*
             * Whether each provider is wired up, never the credential itself.
             * Showing a masked key still tells you its length, and a settings
             * screen is not worth that.
             */
            'providers' => [
                ['name' => 'Email', 'configured' => config('mail.default') !== 'log', 'detail' => config('mail.default')],
                ['name' => 'SMS', 'configured' => config('services.sms.driver') !== 'log', 'detail' => config('services.sms.driver')],
                ['name' => 'Google sign in', 'configured' => filled(config('services.google.client_id')), 'detail' => 'Socialite'],
                ['name' => 'Payments', 'configured' => filled(config('services.razorpay.key_id')), 'detail' => 'Razorpay'],
                [
                    'name' => 'Realtime chat',
                    'configured' => config('broadcasting.default') === 'pusher' && filled(config('broadcasting.connections.pusher.key')),
                    'detail' => config('broadcasting.default') === 'pusher' ? 'Pusher' : config('broadcasting.default').' (polling on shared hosting)',
                ],
                ['name' => 'Human check on registration forms', 'configured' => HumanCheck::enabled(), 'detail' => 'Cloudflare Turnstile'],
            ],
        ]);
    }

    public function updateCompany(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'cin' => ['nullable', 'string', 'max:30'],
            'pan' => ['nullable', 'string', 'max:15'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::put("company.{$key}", $value, 'company');
        }

        $auditor->action('settings.company_updated', context: ['fields' => array_keys($validated)]);

        return back()->with('success', 'Company details saved.');
    }

    /**
     * What the public site shows of the company details, and where to find us
     * elsewhere. A social link has to be on the network it is filed under, so
     * the LinkedIn icon cannot quietly send people somewhere else.
     */
    public function updateSite(Request $request, Auditor $auditor): RedirectResponse
    {
        $rules = [
            'show_email' => ['boolean'],
            'show_phone' => ['boolean'],
            'show_address' => ['boolean'],
            'show_registration' => ['boolean'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s-]{10,18}$/'],
        ];

        foreach (array_keys(SiteIdentity::SOCIAL) as $network) {
            $rules["social.{$network}"] = ['nullable', 'string', 'max:300'];
        }

        $validated = $this->validatedInput($request, $rules, [
            'whatsapp.regex' => 'Use the full number with the country code, like +91 98765 43210.',
        ]);

        $errors = [];
        foreach (array_keys(SiteIdentity::SOCIAL) as $network) {
            $url = trim((string) data_get($validated, "social.{$network}"));

            if ($url !== '' && ! SiteIdentity::belongsTo($network, $url)) {
                $hosts = implode(' or ', SiteIdentity::SOCIAL[$network]['hosts']);
                $errors["social.{$network}"] = "Use the full https:// address of the page on {$hosts}.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = SiteIdentity::settings();

        foreach (['show_email', 'show_phone', 'show_address', 'show_registration'] as $key) {
            Setting::put("site.{$key}", (bool) $validated[$key], 'site');
        }

        Setting::put('site.whatsapp', filled($validated['whatsapp']) ? trim($validated['whatsapp']) : null, 'site');

        foreach (array_keys(SiteIdentity::SOCIAL) as $network) {
            Setting::put("site.social.{$network}", trim((string) data_get($validated, "social.{$network}")) ?: null, 'site');
        }

        $after = SiteIdentity::settings();
        $changes = collect($after)
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->filter(fn ($change) => $change['from'] !== $change['to'])
            ->all();

        if ($changes) {
            $auditor->action('settings.site_updated', context: $changes);
        }

        return back()->with('success', 'Saved. The public site shows it from the next page load.');
    }

    public function updateInvoicing(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
            'next_number' => ['required', 'integer', 'min:1', 'max:999999'],
            'financial_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'default_tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:2000'],
        ], [
            'prefix.regex' => 'Use capital letters, numbers and hyphens only.',
        ]);

        foreach ($validated as $key => $value) {
            Setting::put("invoicing.{$key}", $value, 'invoicing');
        }

        $auditor->action('settings.invoicing_updated', context: ['fields' => array_keys($validated)]);

        return back()->with('success', 'Invoicing settings saved.');
    }

    public function updateTemplate(Request $request, MessageTemplate $template, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedTemplate($request, $template);

        $template->fill([
            'subject' => $template->channel === 'mail' ? $validated['subject'] : null,
            'body' => $validated['body'],
            'is_active' => (bool) $validated['is_active'],
        ]);
        $auditor->updated($template, label: $template->name);
        $template->save();

        return back()->with('success', $template->is_active
            ? 'Template saved and in use from the next message.'
            : 'Template saved. It is switched off, so the built in wording is still what goes out.');
    }

    /**
     * The wording as typed, rendered through the real notification with
     * sample details. Nothing is saved.
     */
    public function previewTemplate(Request $request, MessageTemplate $template): JsonResponse
    {
        $draft = $this->draft($request, $template);
        [$notification, $notifiable] = MessageCatalogue::sampleNotification($template->key);

        return response()->json(MessageTemplate::using($draft, function () use ($template, $notification, $notifiable) {
            if ($template->channel === 'sms') {
                $text = $notification->toSms($notifiable);
                // Anything outside plain ASCII sends the whole message in the
                // larger encoding, which fits 70 characters a part, not 160.
                $unicode = (bool) preg_match('/[^\x00-\x7F]/', $text);
                $length = mb_strlen($text);
                $perPart = $unicode ? ($length > 70 ? 67 : 70) : ($length > 160 ? 153 : 160);

                return ['text' => $text, 'length' => $length, 'parts' => max(1, (int) ceil($length / $perPart)), 'unicode' => $unicode];
            }

            $mail = $notification->toMail($notifiable);

            return ['subject' => $mail->subject, 'html' => (string) $mail->render()];
        }));
    }

    /** Send the wording as typed to the person asking, as an email marked as a test. */
    public function testTemplate(Request $request, MessageTemplate $template, Auditor $auditor): RedirectResponse
    {
        abort_unless($template->channel === 'mail', 404);

        $draft = $this->draft($request, $template);
        [$notification, $notifiable] = MessageCatalogue::sampleNotification($template->key);
        $mail = MessageTemplate::using($draft, fn () => $notification->toMail($notifiable));
        $to = $request->user()->email;

        Mail::to($to)->send(new TemplateTest((string) $mail->subject, (string) $mail->render()));
        $auditor->action('settings.template_tested', $template, [], $template->name);

        return back()->with('success', "Test sent to {$to}, with sample details in place of a real person's.");
    }

    /** What the editor needs for each message: the wording, and what it can and must say. */
    protected function templates(): array
    {
        MessageCatalogue::sync();

        return MessageTemplate::query()
            ->whereIn('key', array_keys(MessageCatalogue::MESSAGES))
            ->orderBy('key')
            ->get()
            ->map(function (MessageTemplate $template) {
                $entry = MessageCatalogue::for($template->key);

                return [
                    'id' => $template->id,
                    'key' => $template->key,
                    'name' => $template->name,
                    'channel' => $template->channel,
                    'sent' => $entry['sent'],
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'variables' => collect($entry['variables'])->map(fn (array $variable, string $name) => [
                        'name' => $name,
                        'description' => $variable[0],
                        'sample' => $variable[1],
                        'required' => in_array($name, $entry['required'], true),
                    ])->values(),
                    'defaults' => ['subject' => $entry['subject'], 'body' => $entry['body']],
                    'isActive' => $template->is_active,
                ];
            })
            ->all();
    }

    /**
     * The submitted wording, checked: every placeholder must be one the
     * message fills in, and the ones it cannot do without must be there.
     *
     * @return array{subject: ?string, body: string, is_active: bool}
     */
    protected function validatedTemplate(Request $request, MessageTemplate $template): array
    {
        $validated = $this->validatedInput($request, [
            'subject' => [Rule::requiredIf($template->channel === 'mail'), 'nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        $entry = MessageCatalogue::for($template->key) ?? abort(404);
        $known = array_keys($entry['variables']);
        $errors = [];

        foreach (['subject', 'body'] as $field) {
            $unknown = array_diff(MessageTemplate::placeholdersIn($validated[$field]), $known);

            if ($unknown) {
                $shown = collect($unknown)->map(fn ($name) => '{{'.$name.'}}')->implode(', ');
                $errors[$field] = "{$shown} is not something this message fills in, so it would arrive exactly as typed. Use one from the list below.";
            }
        }

        $used = array_merge(
            MessageTemplate::placeholdersIn($validated['subject']),
            MessageTemplate::placeholdersIn($validated['body']),
        );
        $missing = array_diff($entry['required'], $used);

        if ($missing && ! isset($errors['body'])) {
            $shown = collect($missing)->map(fn ($name) => '{{'.$name.'}}')->implode(' and ');
            $errors['body'] = "This message has to include {$shown}, or the person receiving it cannot do what it is for.";
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /** An unsaved copy of the template carrying the wording as typed, checked the same way as a save. */
    protected function draft(Request $request, MessageTemplate $template): MessageTemplate
    {
        $validated = $this->validatedTemplate($request, $template);

        return (clone $template)->forceFill([
            'subject' => $validated['subject'],
            'body' => $validated['body'],
        ]);
    }
}
