<?php

use App\Models\Permission;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketRaised;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->student = User::factory()->student()->create();
});

it('lets a student raise a ticket, and tells staff who work the desk', function () {
    Notification::fake();
    $desk = User::factory()->admin()->create();
    $desk->permissions()->attach(Permission::query()->where('key', 'support.manage')->sole());
    $other = User::factory()->admin()->create();

    $this->actingAs($this->student)->get('/student/support/new')->assertOk();
    $response = $this->actingAs($this->student)->post('/student/support', [
        'subject' => 'Cannot open week 3', 'category' => 'course', 'body' => 'It says locked.',
    ]);

    $ticket = SupportTicket::query()->sole();
    $response->assertRedirect("/student/support/{$ticket->id}");

    expect($ticket->requester_id)->toBe($this->student->id)
        ->and($ticket->contract_id)->toBeNull()
        ->and($ticket->response_due_at)->toBeNull();

    Notification::assertSentTo($desk, TicketRaised::class);
    Notification::assertNotSentTo($other, TicketRaised::class);
});

it('shows a student only their own tickets, and never a staff note', function () {
    $mine = SupportTicket::query()->create(['requester_id' => $this->student->id, 'subject' => 'Mine', 'body' => 'b']);
    $theirs = SupportTicket::query()->create(['requester_id' => User::factory()->student()->create()->id, 'subject' => 'Theirs', 'body' => 'b']);
    $mine->messages()->create(['author_id' => null, 'body' => 'Staff only: check payment', 'is_internal' => true]);
    $mine->messages()->create(['author_id' => null, 'body' => 'We are looking into it.']);

    $this->actingAs($this->student)->get('/student/support')->assertOk()
        ->assertInertia(fn ($page) => $page->has('table.rows', 1)->where('table.rows.0.subject', 'Mine'));

    $this->actingAs($this->student)->get("/student/support/{$mine->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->has('messages', 1)->where('messages.0.body', 'We are looking into it.'));

    $this->actingAs($this->student)->get("/student/support/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->student)->post("/student/support/{$theirs->id}/reply", ['body' => 'x'])->assertNotFound();
});

it('lets a student reply and close, and the staff desk shows them as a student', function () {
    $ticket = SupportTicket::query()->create(['requester_id' => $this->student->id, 'subject' => 'Help', 'body' => 'b', 'status' => 'waiting_on_client']);

    $this->actingAs($this->student)->post("/student/support/{$ticket->id}/reply", ['body' => 'Here is the screenshot.'])->assertSessionHas('success');
    expect($ticket->fresh()->status)->toBe('in_progress')
        ->and(TicketMessage::query()->sole()->author_id)->toBe($this->student->id);

    $ticket->update(['status' => 'resolved']);
    $this->actingAs($this->student)->post("/student/support/{$ticket->id}/close", ['satisfaction' => 4]);
    expect($ticket->fresh()->status)->toBe('closed')->and($ticket->fresh()->satisfaction)->toBe(4);

    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get("/admin/tickets/{$ticket->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('ticket.client.role', 'student')->where('ticket.client.href', "/admin/students/{$this->student->id}"));
});
