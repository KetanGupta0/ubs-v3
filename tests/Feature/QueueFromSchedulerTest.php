<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function queueEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (Event $event) => str_contains($event->command, 'queue:work'));
}

it('drains the queue from the scheduler when there is no worker, as on shared hosting', function () {
    config(['queue.from_scheduler' => true]);

    $event = queueEvent();

    expect($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('--stop-when-empty')
        ->and($event->command)->toContain('--max-time=50')
        ->and($event->filtersPass(app()))->toBeTrue();
});

it('leaves the queue to a real worker otherwise', function () {
    config(['queue.from_scheduler' => false]);

    expect(queueEvent()->filtersPass(app()))->toBeFalse();
});

it('finishes a run well inside the time a job is reserved for', function () {
    // If a run outlived retry_after, a slow job could be handed out twice.
    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(50);
});
