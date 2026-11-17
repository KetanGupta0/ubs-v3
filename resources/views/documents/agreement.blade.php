{{-- An agreement, exactly as sent. Its SHA-256 is recorded, and a signature is
     a signature of this file. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $agreement->kindLabel() }} {{ $agreement->number }}</title>
    @include('documents._style')
</head>
<body>
@include('documents._parties', [
    'from' => $parties['from'],
    'to' => $parties['to'],
    'title' => $agreement->kindLabel(),
    'meta' => [
        'Number' => $agreement->number,
        'Version' => $agreement->version > 1 ? (string) $agreement->version : null,
        'Issued' => ($agreement->sent_at ?? now())->format('j M Y'),
        'Effective' => $agreement->effective_on?->format('j M Y'),
        'Ends' => $agreement->expires_on?->format('j M Y'),
    ],
])

<h1>{{ $agreement->title }}</h1>
@if($agreement->project)<p class="muted">For the project {{ $agreement->project->name }}{{ $agreement->project->code ? ' ('.$agreement->project->code.')' : '' }}.</p>@endif

<div>{!! $html !!}</div>

<p class="note">
    {{ $agreement->number }}{{ $agreement->version > 1 ? ', version '.$agreement->version : '' }}.
    This text is fixed from the moment it was issued; any change is made in a new version.
</p>
</body>
</html>
