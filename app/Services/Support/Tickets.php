<?php

namespace App\Services\Support;

use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\TicketRaised;
use Illuminate\Support\Facades\Notification;

/**
 * Raising a client's ticket, the one way the portal and the app both do it
 * (Step I). The contract and project are re-resolved through the client's own
 * relations, so an id typed into a request cannot attach the ticket to
 * somebody else's; the response clock is copied from the contract as it is
 * now; and whoever works the support desk hears of it.
 *
 * Until November 2026 a ticket raised from the app told nobody.
 */
class Tickets
{
    /**
     * @param  User  $client  The organisation the ticket is filed under.
     * @param  User  $requester  The person who raised it, and who is answered.
     * @param  array{subject: string, body: string, priority: string, category?: ?string, contract_id?: ?int, project_id?: ?int}  $input
     */
    public function raiseForClient(User $client, User $requester, array $input): SupportTicket
    {
        $contract = ($input['contract_id'] ?? null)
            ? MaintenanceContract::query()->forClient($client)->find($input['contract_id'])
            : MaintenanceContract::query()->forClient($client)->active()->first();

        $project = ($input['project_id'] ?? null)
            ? Project::query()->forClient($client)->find($input['project_id'])
            : null;

        $ticket = SupportTicket::query()->create([
            'requester_id' => $requester->id,
            'contract_id' => $contract?->id,
            'project_id' => $project?->id ?? $contract?->project_id,
            'subject' => $input['subject'],
            'body' => $input['body'],
            'category' => $input['category'] ?? null,
            'priority' => $input['priority'],
            'status' => 'open',
            'response_due_at' => $contract ? now()->addHours($contract->response_hours) : null,
            'resolution_due_at' => $contract ? now()->addHours($contract->resolution_hours) : null,
        ]);

        $this->tellTheDesk($ticket);

        return $ticket;
    }

    /** Everybody who works the support desk, and the owner. */
    public function tellTheDesk(SupportTicket $ticket): void
    {
        $desk = User::query()->holdingPermission('support.manage')->active()->get();

        if ($desk->isNotEmpty()) {
            Notification::send($desk, new TicketRaised($ticket));
        }
    }
}
