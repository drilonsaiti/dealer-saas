{{-- Evidence page of a signed document (concept 8.5, step 6), in the document's language. --}}
@php
    $e = $d['evidence'];
    $roles = ['customer' => __('Customer'), 'dealer' => __('Dealer')];
    $methods = ['on_device' => __('Signed on the dealer’s device'), 'link' => __('Signed by link')];
    $docTypes = ['id_card' => __('ID card'), 'passport' => __('Passport'), 'driving_licence' => __('Driving licence'), 'residence_permit' => __('Residence permit')];
    $identified = function (array $id) use ($docTypes): string {
        return match ($id['method'] ?? null) {
            'id_check' => __('ID checked by :name: :type no. :number', ['name' => $id['checked_by'] ?? '–', 'type' => $docTypes[$id['doc_type'] ?? ''] ?? ($id['doc_type'] ?? ''), 'number' => $id['doc_number'] ?? '']),
            'link_code' => __('Link sent to :email; one-time code sent to :to, confirmed :at', ['email' => $id['email'] ?? '–', 'to' => $id['code_sent_to'] ?? '–', 'at' => isset($id['code_verified_at']) ? \Illuminate\Support\Carbon::parse($id['code_verified_at'])->timezone('Europe/Zurich')->format('d.m.Y H:i:s') : '–']),
            'login' => __('Logged in as :user', ['user' => $id['user'] ?? '–']).(($id['two_factor'] ?? false) ? ' · '.__('with two-factor authentication') : ''),
            default => '–',
        };
    };
@endphp
<div class="page-break"></div>
<h1 style="font-size: 14pt">{{ __('Signature evidence') }}</h1>
<div class="docmeta">{{ __('No.') }} {{ $d['number'] }}</div>

<table class="lines">
    <tr><td style="width: 38%">{{ __('Fingerprint of the finalised document (SHA-256)') }}</td><td style="font-family: monospace; font-size: 7.5pt; word-break: break-all">{{ $e['document_sha256'] }}</td></tr>
    <tr><td>{{ __('Fingerprint of the contract data (SHA-256)') }}</td><td style="font-family: monospace; font-size: 7.5pt; word-break: break-all">{{ $e['data_sha256'] }}</td></tr>
    <tr><td>{{ __('Signing started') }}</td><td>{{ $e['started_at'] ? \Illuminate\Support\Carbon::parse($e['started_at'])->format('d.m.Y H:i:s') : '–' }}</td></tr>
</table>

@foreach ($e['signers'] as $s)
    <h2>{{ $s['position'] }}. {{ $roles[$s['role']] ?? $s['role'] }}: {{ $s['name'] }}</h2>
    <table class="lines">
        <tr><td style="width: 38%">{{ __('Method') }}</td><td>{{ $methods[$s['method']] ?? $s['method'] }}</td></tr>
        <tr><td>{{ __('Identification') }}</td><td>{{ $identified($s['identification']) }}</td></tr>
        <tr><td>{{ __('Signed at') }}</td><td>{{ $s['signed_at'] ? \Illuminate\Support\Carbon::parse($s['signed_at'])->format('d.m.Y H:i:s') : '–' }} ({{ __('Swiss time') }}), {{ $s['place'] }}</td></tr>
        <tr><td>{{ __('IP address') }}</td><td>{{ $s['ip'] ?? '–' }}</td></tr>
        <tr><td>{{ __('Device') }}</td><td style="font-size: 7.5pt">{{ $s['device'] ?? '–' }}</td></tr>
        <tr><td>{{ __('Fingerprint of the signature (SHA-256)') }}</td><td style="font-family: monospace; font-size: 7.5pt; word-break: break-all">{{ $s['signature_sha256'] }}</td></tr>
    </table>
@endforeach

<p class="muted" style="margin-top: 6mm">{{ __('Signed with a simple electronic signature. Each signer read the whole document and confirmed it before signing. The PDF is sealed with the certificate of the platform; any change after signing breaks the seal.') }}</p>
