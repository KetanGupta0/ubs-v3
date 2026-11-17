{{-- Certificate of signing, appended to a signed copy (CL2). It names the
     exact file that was signed by its SHA-256, so anybody holding the
     original can check that this is what was agreed. --}}
<div style="page-break-before: always"></div>
<h1>Certificate of signing</h1>
<p class="muted">
    {{ $documentLabel }} {{ $number }}{{ $version > 1 ? ', version '.$version : '' }}: {{ $title }}.
</p>
<p>
    The signatures below were made over the PDF of this document as it was sent, whose SHA-256 is
    <br><strong style="font-family: DejaVu Sans Mono, monospace; font-size: 9px">{{ $sha256 }}</strong>.
    <br>That file can be downloaded from the account it was sent to. The pages before this one reproduce it.
</p>

@foreach($signatures as $signature)
    <table class="party" style="margin-top: 12px">
        <tr>
            <td style="width: 34%">
                <div class="label">{{ $signature['party'] }}</div>
                <div style="font-size: 15px; font-style: italic; margin-top: 4px">{{ $signature['name'] }}</div>
                @if($signature['designation'])<div class="muted">{{ $signature['designation'] }}</div>@endif
                @if($signature['organisation'])<div class="muted">{{ $signature['organisation'] }}</div>@endif
            </td>
            <td>
                <div><span class="label">Signed</span> {{ $signature['signedAt'] }}</div>
                <div><span class="label">Signer</span> {{ $signature['email'] }}</div>
                <div><span class="label">Proved by</span> {{ $signature['verified'] }}</div>
                <div><span class="label">From</span> {{ $signature['ip'] }}</div>
                @if($signature['userAgent'])<div class="muted" style="font-size: 8px">{{ \Illuminate\Support\Str::limit($signature['userAgent'], 140) }}</div>@endif
                <div class="muted" style="font-size: 8px">Document SHA-256 at signing: {{ $signature['sha256'] }}</div>
            </td>
        </tr>
    </table>
@endforeach

<p class="note">
    Signed with a one time code sent to the signer's registered address, under the platform's built-in electronic
    signature. Each signature is kept unchanged with the details above.
</p>
