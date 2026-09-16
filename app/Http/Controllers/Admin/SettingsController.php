<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'invoicing' => [
                'prefix' => Setting::get('invoicing.prefix', 'UBS'),
                'next_number' => Setting::get('invoicing.next_number', 1),
                'financial_year_start_month' => Setting::get('invoicing.financial_year_start_month', 4),
                'default_tax_rate' => Setting::get('invoicing.default_tax_rate', 18),
                'terms' => Setting::get('invoicing.terms'),
            ],
            'templates' => MessageTemplate::query()
                ->orderBy('key')
                ->get()
                ->map(fn (MessageTemplate $template) => [
                    'id' => $template->id,
                    'key' => $template->key,
                    'name' => $template->name,
                    'channel' => $template->channel,
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'variables' => $template->variables ?? [],
                    'isActive' => $template->is_active,
                ]),

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
        $validated = $request->validate([
            'subject' => [Rule::requiredIf($template->channel === 'mail'), 'nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        $template->fill($validated);
        $auditor->updated($template, label: $template->name);
        $template->save();

        return back()->with('success', 'Template saved.');
    }
}
