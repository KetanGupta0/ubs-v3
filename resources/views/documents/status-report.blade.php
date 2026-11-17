{{-- Where a project stands, as of the moment it was printed. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Status report {{ $project->code }}</title>
    @include('documents._style')
</head>
<body>
@include('documents._parties', [
    'from' => $parties['from'],
    'to' => $parties['to'],
    'title' => 'Project status report',
    'meta' => [
        'Project' => $project->code,
        'As of' => now()->format('j M Y, g:i a'),
        'Status' => $project->statusLabel(),
    ],
])

<h1>{{ $project->name }}</h1>
<table style="width:100%; margin: 6px 0 14px">
    <tr>
        <td style="width:70%"><div class="bar"><div style="width: {{ (int) $project->progress_percent }}%"></div></div></td>
        <td class="right"><strong>{{ (int) $project->progress_percent }}%</strong> complete</td>
    </tr>
</table>

<h2>Milestones</h2>
@if($project->milestones->isEmpty())
    <p class="muted">No milestones recorded.</p>
@else
    <table class="items">
        <thead><tr><th>Milestone</th><th>Due</th><th class="right">Progress</th><th>Done</th></tr></thead>
        <tbody>
        @foreach($project->milestones as $milestone)
            <tr>
                <td>{{ $milestone->title }}</td>
                <td>{{ $milestone->due_date?->format('j M Y') ?? '—' }}</td>
                <td class="right">{{ (int) $milestone->progress_percent }}%</td>
                <td>{{ $milestone->completed_at?->format('j M Y') ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Recent updates</h2>
@forelse($updates as $update)
    <h3>{{ $update->title ?: 'Update' }} <span class="muted" style="font-weight:normal">· {{ $update->created_at->format('j M Y') }}</span></h3>
    @foreach(preg_split('/\n{2,}/', trim($update->body)) as $paragraph)
        <p>{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
@empty
    <p class="muted">No updates yet.</p>
@endforelse

@if($tickets->isNotEmpty())
    <h2>Open tickets</h2>
    <ul>@foreach($tickets as $ticket)<li>{{ $ticket->reference }}: {{ $ticket->subject }} ({{ str_replace('_', ' ', $ticket->status) }})</li>@endforeach</ul>
@endif

<p class="note">A snapshot of the project at the time above, from the records in your account.</p>
</body>
</html>
