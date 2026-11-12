<?php

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createSupport();
    Storage::fake('local');
    Storage::fake('private');
});

it('brings a student ticket across: first message as its body, replies in order, staff notes still staff-only', function () {
    $student = LegacyDatabase::user(['email' => 'learner@example.test']);
    $staff = LegacyDatabase::user(['email' => 'desk@example.test', 'role' => 'admin']);
    $ticket = LegacyDatabase::insert('support_tickets', ['student_id' => $student, 'subject' => 'Certificate name wrong', 'status' => 'resolved', 'priority' => 'medium', 'category' => 'course', 'created_at' => '2026-06-01 09:00:00']);
    LegacyDatabase::insert('ticket_replies', ['ticket_id' => $ticket, 'sender_id' => $student, 'sender_type' => 'student', 'body' => 'My name is spelt wrong.', 'created_at' => '2026-06-01 09:00:00']);
    LegacyDatabase::insert('ticket_replies', ['ticket_id' => $ticket, 'sender_id' => $staff, 'sender_type' => 'admin', 'body' => 'Reissue after checking ID', 'is_internal' => true, 'created_at' => '2026-06-01 10:00:00']);
    LegacyDatabase::insert('ticket_replies', ['ticket_id' => $ticket, 'sender_id' => $staff, 'sender_type' => 'admin', 'body' => '<p>Fixed and reissued.</p>', 'created_at' => '2026-06-01 11:00:00']);

    $this->artisan('legacy:import', ['--all-generation-one' => true])->assertSuccessful();

    $imported = SupportTicket::query()->sole();
    expect($imported->requester_id)->toBe(User::query()->where('email', 'learner@example.test')->value('id'))
        ->and($imported->body)->toBe('My name is spelt wrong.')
        ->and($imported->priority)->toBe('normal')
        ->and($imported->status)->toBe('resolved')
        ->and($imported->contract_id)->toBeNull()
        ->and($imported->first_response_at->toDateTimeString())->toBe('2026-06-01 11:00:00');

    $messages = TicketMessage::query()->orderBy('created_at')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->is_internal)->toBeTrue()
        ->and($messages[1]->body)->toBe('Fixed and reissued.');

    // Again: no duplicates.
    $this->artisan('legacy:import', ['--all-generation-one' => true])->assertSuccessful();
    expect(SupportTicket::query()->count())->toBe(1)->and(TicketMessage::query()->count())->toBe(2);
});
