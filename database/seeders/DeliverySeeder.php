<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\ApiKeyPlan;
use App\Models\DocumentFolder;
use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * A worked example of the client module, for development.
 *
 * One client with a project in flight, a sent proposal, an open ticket, an
 * outstanding invoice and a renewal coming up, so every screen has something on
 * it rather than an empty state. Re-running this is safe.
 */
class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        $client = User::query()->role(Role::Client)->first();
        $admin = User::query()->role(Role::Admin)->first();

        if (! $client) {
            $this->command?->warn('No client account, so there is nothing to attach delivery data to.');

            return;
        }

        $this->seedApiPlans();

        $project = $this->seedProject($client, $admin);
        $this->seedProposal($client, $admin, $project);
        $this->seedDocuments($client, $project, $admin);
        $contract = $this->seedContract($client, $project);
        $this->seedTicket($client, $contract, $project);
        $this->seedSubscription($client, $contract);
        $this->seedBilling($client, $admin, $project);
        $this->seedApiKey($client, $project);

        $this->command?->info('Delivery example ready for '.$client->name.'.');
    }

    protected function seedProject(User $client, ?User $admin): Project
    {
        $project = Project::query()->firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Warehouse management system'],
            [
                'summary' => 'Stock, despatch and returns across three warehouses, with a driver app.',
                'scope' => "In: inbound receipts, stock moves, picking, despatch, returns, reporting.\nOut: accounting integration, which is a later phase.",
                'status' => 'in_progress',
                'phase' => 'Build',
                'progress_percent' => 62,
                'start_date' => today()->subMonths(2),
                'target_date' => today()->addMonths(2),
                'budget' => Money::toPaise(850000),
                'manager_id' => $admin?->id,
                'team' => ['Ketan Gupta', 'Priya Nair', 'Arjun Mehta'],
                'staging_url' => 'https://staging.example.test',
            ],
        );

        $milestones = [
            ['Discovery and specification', -55, 100, null],
            ['Design sign off', -35, 100, 150000],
            ['Inbound and stock module', -10, 100, 250000],
            ['Picking and despatch', 20, 45, 250000],
            ['Driver app and handover', 55, 0, 200000],
        ];

        foreach ($milestones as $index => [$title, $dueOffset, $progress, $payment]) {
            $project->milestones()->firstOrCreate(
                ['title' => $title],
                [
                    'due_date' => today()->addDays($dueOffset),
                    'progress_percent' => $progress,
                    'completed_at' => $progress === 100 ? today()->addDays($dueOffset) : null,
                    'payment_amount' => $payment ? Money::toPaise($payment) : null,
                    'sort_order' => $index,
                ],
            );
        }

        $updates = [
            ['Picking screens on staging', 'The picking flow is on staging for you to try. Scanning works on an Android device; iOS needs one more permission which we are sorting out.', true],
            ['Stock module signed off', 'Inbound receipts and stock moves are done and tested against the three warehouse layouts you sent.', true],
            ['Their API credentials expired again', 'Third time this month. Worth raising with them before the despatch integration starts.', false],
        ];

        foreach ($updates as [$title, $body, $visible]) {
            $project->updates()->firstOrCreate(
                ['title' => $title],
                ['body' => $body, 'visible_to_client' => $visible, 'author_id' => $admin?->id],
            );
        }

        return $project;
    }

    protected function seedProposal(User $client, ?User $admin, Project $project): void
    {
        if (Proposal::query()->where('client_id', $client->id)->exists()) {
            return;
        }

        $proposal = Proposal::query()->create([
            'client_id' => $client->id,
            'project_id' => $project->id,
            'title' => 'Driver application, phase two',
            'summary' => 'A native driver application for despatch confirmation and proof of delivery.',
            'body' => "The warehouse system now knows what leaves the building. This phase follows it to the door.\n\nDrivers get a route list, capture a signature or photograph on delivery, and work offline through a signal blackspot with everything syncing when they are back in range.",
            'deliverables' => [
                'Android and iOS applications, published under your own developer accounts',
                'Offline capture with automatic sync',
                'Proof of delivery visible in the warehouse system within seconds',
                'Six weeks of support after launch, included',
            ],
            'assumptions' => [
                'Your developer accounts exist and we are given access, rather than publishing under ours',
                'Route planning stays with your current provider; we consume their output',
                'One round of design revisions is included',
            ],
            'timeline' => 'Ten weeks from sign off',
            'valid_until' => today()->addDays(21),
            'status' => 'sent',
            'sent_at' => now()->subDays(4),
            'created_by' => $admin?->id,
        ]);

        $quotation = $proposal->quotation()->create([
            'client_id' => $client->id,
            'status' => 'sent',
            'notes' => 'Payable in three parts: on sign off, at beta, and on store approval.',
        ]);

        foreach ([
            ['Discovery and design', 1, 'phase', 120000],
            ['Android and iOS build', 1, 'phase', 480000],
            ['Integration and testing', 1, 'phase', 140000],
            ['Support after launch', 6, 'weeks', 15000],
        ] as $index => [$description, $quantity, $unit, $rate]) {
            $quotation->items()->create([
                'description' => $description,
                'hsn_sac' => '998314',
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => Money::toPaise($rate),
                'tax_rate' => 18,
                'sort_order' => $index,
            ]);
        }

        $quotation->recalculate();
    }

    protected function seedDocuments(User $client, Project $project, ?User $admin): void
    {
        DocumentFolder::query()->firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Specifications'],
            ['project_id' => $project->id],
        );

        DocumentFolder::query()->firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Handover'],
            ['project_id' => $project->id],
        );

        // No file is written: a seeder that invents documents would put rows on
        // a screen whose download button then fails. The folders are enough to
        // show the shape of the repository.
    }

    protected function seedContract(User $client, Project $project): MaintenanceContract
    {
        return MaintenanceContract::query()->firstOrCreate(
            ['client_id' => $client->id, 'project_id' => $project->id],
            [
                'plan' => 'Standard AMC',
                'scope' => [
                    'Bug fixes in anything we built',
                    'Security patches and dependency updates',
                    'Server monitoring, with us watching rather than you',
                    'Up to four hours of small changes a month',
                ],
                'exclusions' => [
                    'New features, which are quoted separately',
                    'Third party outages outside our control',
                    'Hardware and network at your sites',
                ],
                'starts_on' => today()->subMonths(1),
                'ends_on' => today()->addMonths(11),
                'response_hours' => 8,
                'resolution_hours' => 48,
                'included_tickets' => 48,
                'amount' => Money::toPaise(96000),
                'billing_interval' => 'yearly',
                'status' => 'active',
            ],
        );
    }

    protected function seedTicket(User $client, MaintenanceContract $contract, Project $project): void
    {
        if (SupportTicket::query()->where('client_id', $client->id)->exists()) {
            return;
        }

        $ticket = SupportTicket::query()->create([
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'subject' => 'Despatch note prints without the batch number',
            'body' => "Since Tuesday the despatch note comes out without the batch number in the second column. It is there on screen, just not on the print.\n\nHappens on every order we have tried.",
            'category' => 'Printing',
            'priority' => 'high',
            'status' => 'in_progress',
            'response_due_at' => now()->subHours(20)->addHours($contract->response_hours),
            'resolution_due_at' => now()->subHours(20)->addHours($contract->resolution_hours),
            'first_response_at' => now()->subHours(18),
            'created_at' => now()->subHours(20),
        ]);

        $ticket->messages()->create([
            'body' => 'Thanks for the detail. Reproduced it here: the batch column was dropped from the print template in the last release. Fix is written and going through review now.',
            'is_internal' => false,
            'created_at' => now()->subHours(18),
        ]);
    }

    protected function seedSubscription(User $client, MaintenanceContract $contract): void
    {
        Subscription::query()->firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Managed hosting, production'],
            [
                'description' => 'Two application servers and a managed database, with backups kept for thirty days.',
                'amount' => Money::toPaise(18000),
                'interval' => 'quarterly',
                'starts_on' => today()->subMonths(4),
                'renews_on' => today()->addDays(19),
                'auto_renew' => true,
                'status' => 'active',
                'subscribable_type' => MaintenanceContract::class,
                'subscribable_id' => $contract->id,
            ],
        );
    }

    protected function seedBilling(User $client, ?User $admin, Project $project): void
    {
        if (PaymentRequest::query()->where('user_id', $client->id)->exists()) {
            return;
        }

        $milestone = $project->milestones()->where('progress_percent', '<', 100)->first();

        $request = PaymentRequest::query()->create([
            'user_id' => $client->id,
            'payable_type' => $milestone ? $milestone::class : null,
            'payable_id' => $milestone?->id,
            'title' => 'Milestone 4: picking and despatch',
            'description' => 'Due on the picking and despatch module reaching beta, as set out in the proposal.',
            'due_on' => today()->addDays(10),
            'raised_by' => $admin?->id,
        ]);

        $request->price(Money::toPaise(250000), 18)->save();

        app(Invoicer::class)->issueFor($request);
    }

    protected function seedApiPlans(): void
    {
        foreach ([
            ['Starter', 'For trying things out and small integrations.', 10000, 60, 0, 0],
            ['Growth', 'For a live product with steady traffic.', 250000, 600, 4000, 1],
            ['Scale', 'High volume, with a rate limit to match.', 2000000, 3000, 18000, 2],
        ] as [$name, $description, $quota, $rate, $price, $order]) {
            ApiKeyPlan::query()->updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                [
                    'name' => $name,
                    'description' => $description,
                    'monthly_quota' => $quota,
                    'rate_limit_per_minute' => $rate,
                    'price' => Money::toPaise($price),
                    'interval' => 'monthly',
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );
        }
    }

    protected function seedApiKey(User $client, Project $project): void
    {
        if (ApiKey::query()->where('client_id', $client->id)->exists()) {
            return;
        }

        $plan = ApiKeyPlan::query()->where('slug', 'growth')->first();
        $generated = ApiKey::generate('live');

        $key = ApiKey::query()->create([
            'client_id' => $client->id,
            'api_key_plan_id' => $plan?->id,
            'project_id' => $project->id,
            'label' => 'Warehouse system, production',
            'environment' => 'live',
            'key_prefix' => $generated['prefix'],
            'key_hash' => $generated['hash'],
            'last_four' => $generated['lastFour'],
            'status' => 'active',
            'quota_used' => 38420,
            'quota_period_start' => today()->startOfMonth(),
            'last_used_at' => now()->subMinutes(12),
        ]);

        // Usage that varies rather than a flat line, so the chart shows what a
        // real one would: quiet weekends and busier weekdays.
        foreach (range(29, 0) as $daysAgo) {
            $date = today()->subDays($daysAgo);
            $weekend = $date->isWeekend();

            $key->usage()->updateOrCreate(
                ['date' => $date->toDateString()],
                [
                    'request_count' => $weekend
                        ? random_int(300, 900)
                        : random_int(900, 2400),
                    'error_count' => random_int(0, 12),
                ],
            );
        }
    }
}
