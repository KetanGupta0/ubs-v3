<x-mail::message>
@if ($image)
<img src="{{ $image }}" alt="{{ $imageAlt }}" style="max-width: 100%; height: auto; border-radius: 8px; margin-bottom: 16px;">

@endif
{!! $html !!}

<x-mail::subcopy>
{{ $why }} You get greetings and the occasional announcement from us. [Stop them]({{ $unsubscribe }}): one click is enough, and it does not stop messages about your account, such as sign in codes, receipts and reminders.
</x-mail::subcopy>
</x-mail::message>
