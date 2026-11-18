<?php

use App\Models\ClientMember;
use App\Models\Document;
use App\Models\MaintenanceContract;
use App\Models\Permission;
use App\Models\ServiceReport;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ServiceReportPublished;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    $this->travelTo(Carbon::parse('2026-11-01 06:15'));

    $this->staff = User::factory()->admin()->create();
    $this->staff->permissions()->attach(Permission::query()->whereIn('key', ['support.manage'])->get());
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $this->technical = User::factory()->client()->create();
    ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $this->technical->id, 'role' => 'technical', 'joined_at' => now()]);

    $this->contract = MaintenanceContract::query()->create([
        'client_id' => $this->acme->id, 'reference' => 'AMC-0007', 'plan' => 'Standard', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        'response_hours' => 4, 'resolution_hours' => 24, 'included_tickets' => 10, 'included_hours' => 20, 'status' => 'active',
    ]);

    $ticket = fn (string $reference, string $created, ?string $responded, ?string $resolved) => SupportTicket::query()->create([
        'client_id' => $this->acme->id, 'requester_id' => $this->acme->id, 'contract_id' => $this->contract->id, 'reference' => $reference,
        'subject' => "Ticket {$reference}", 'body' => 'Something', 'status' => $resolved ? 'resolved' : 'open',
        'created_at' => $created, 'response_due_at' => Carbon::parse($created)->addHours(4), 'resolution_due_at' => Carbon::parse($created)->addHours(24),
        'first_response_at' => $responded, 'resolved_at' => $resolved,
    ]);
    // In time; late on both counts; raised at the end of the month and not yet due.
    $ticket('TKT-1', '2026-10-03 10:00', '2026-10-03 11:00', '2026-10-03 18:00');
    $ticket('TKT-2', '2026-10-10 10:00', '2026-10-10 16:00', '2026-10-12 10:00');
    $ticket('TKT-3', '2026-10-31 22:00', null, null);
    $ticket('TKT-0', '2026-09-20 10:00', '2026-09-20 11:00', '2026-09-20 12:00');
});

it('drafts last month\'s report with the ticket figures, once however often it runs', function () {
    $this->artisan('service-reports:draft')->assertSuccessful();
    $this->artisan('service-reports:draft');

    $report = ServiceReport::query()->sole();
    expect($report->month->toDateString())->toBe('2026-10-01')
        ->and($report->status)->toBe('draft')
        ->and($report->figures['opened'])->toBe(3)
        ->and($report->figures['resolved'])->toBe(2)
        ->and($report->figures['responseMet'])->toBe(50)
        ->and($report->figures['resolutionMet'])->toBe(50)
        ->and($report->figures['ticketsThisYear'])->toBe(4)
        ->and(collect($report->figures['tickets'])->firstWhere('reference', 'TKT-3')['responseMet'])->toBeNull();
});

it('is finished and published by a person, into the client\'s documents', function () {
    $this->artisan('service-reports:draft');
    $report = ServiceReport::query()->sole();
    $base = "/admin/service-reports/{$report->id}";

    $this->actingAs($this->staff)->put($base, ['uptime_percent' => 101])->assertSessionHasErrors('uptime_percent');
    $this->actingAs($this->staff)->put($base, ['uptime_percent' => 99.95, 'hours_used' => 12.5, 'work_done' => "- Patched the picking screen\n- Renewed the certificate"])->assertSessionHas('success');
    expect($this->actingAs($this->staff)->get("{$base}/preview")->assertOk()->getContent())->toStartWith('%PDF');

    $this->actingAs($this->staff)->put($base, ['uptime_percent' => 99.95, 'hours_used' => 12.5, 'work_done' => 'Done.', 'publish' => true])->assertSessionHas('success');

    $report->refresh();
    $document = Document::query()->sole();
    expect($report->status)->toBe('published')
        ->and($report->document_id)->toBe($document->id)
        ->and($document->client_id)->toBe($this->acme->id)
        ->and($document->category)->toBe('Service report')
        ->and($document->name)->toBe('Service report, October 2026 (AMC-0007).pdf');
    Storage::disk('private')->assertExists($document->path);
    Notification::assertSentTo([$this->acme, $this->technical], ServiceReportPublished::class);
    $this->actingAs($this->technical)->get("/client/documents/{$document->id}/download")->assertOk();

    // Published is the client's record.
    $this->actingAs($this->staff)->put($base, ['uptime_percent' => 90])->assertSessionHas('error');
    $this->artisan('service-reports:draft');
    expect($report->fresh()->uptime_percent)->toBe(99.95)
        ->and(ServiceReport::query()->count())->toBe(1);
});
