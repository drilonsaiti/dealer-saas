{{-- Shared frame of generated documents: header with logo and company, bank footer. Uses only the snapshot ($d). --}}
@php
    use App\Support\Money;
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['company']['brand_color'] ?? '')) ? $d['company']['brand_color'] : '#1a1a1a';
@endphp
<!doctype html>
<html lang="{{ $d['locale'] }}">
<head>
<meta charset="utf-8">
<title>@yield('title') {{ $d['number'] }}</title>
<style>
    @page { size: A4; margin: 14mm 16mm 18mm; }
    * { box-sizing: border-box; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 9.2pt; color: #1a1a1a; line-height: 1.38; margin: 0; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 0.6mm solid {{ $accent }}; padding-bottom: 3mm; margin-bottom: 5mm; }
    .head img { max-height: 16mm; max-width: 60mm; }
    .head .company { text-align: right; font-size: 8.2pt; color: #444; }
    .head .company strong { color: #1a1a1a; font-size: 9.5pt; }
    h1 { font-size: 17pt; margin: 0; letter-spacing: -0.2pt; }
    .docmeta { color: #555; margin: 1mm 0 5mm; }
    h2 { font-size: 10pt; text-transform: uppercase; letter-spacing: 0.4pt; color: {{ $accent }}; margin: 5mm 0 1.5mm; }
    .parties { display: flex; gap: 6mm; }
    .parties > div { flex: 1; border: 0.25mm solid #cfcfcf; border-radius: 1.5mm; padding: 2.5mm 3mm; }
    .parties .role { font-size: 7.8pt; color: #666; text-transform: uppercase; letter-spacing: 0.3pt; }
    table { width: 100%; border-collapse: collapse; }
    .facts td { padding: 1mm 1.5mm 1mm 0; vertical-align: top; width: 25%; }
    .facts td span { display: block; font-size: 7.6pt; color: #666; }
    .lines th { text-align: left; font-size: 7.8pt; color: #555; font-weight: 600; border-bottom: 0.3mm solid #999; padding: 1mm 1.5mm; }
    .lines td { padding: 1.1mm 1.5mm; border-bottom: 0.2mm solid #e2e2e2; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .sum td { padding: 0.9mm 1.5mm; }
    .sum .total td { font-weight: 700; border-top: 0.4mm solid #1a1a1a; font-size: 10pt; }
    .box { border: 0.25mm solid #cfcfcf; border-radius: 1.5mm; padding: 2.5mm 3mm; }
    .pre { white-space: pre-line; }
    .checks { margin: 0; padding: 0; list-style: none; }
    .checks li::before { content: "☐  "; }
    .checks li.done::before { content: "☑  "; }
    .clauses { padding-left: 5mm; margin: 0; }
    .clauses li { margin-bottom: 1.6mm; }
    .page-break { break-before: page; }
    .signatures { display: flex; gap: 12mm; margin-top: 12mm; break-inside: avoid; }
    .signatures > div { flex: 1; }
    .sig-line { border-bottom: 0.3mm solid #1a1a1a; height: 9mm; }
    .sig-line.sign { height: 20mm; position: relative; }
    .sig-line.sign img { position: absolute; bottom: 1mm; left: 0; max-height: 18mm; max-width: 100%; }
    .sig-label { font-size: 8pt; color: #555; margin: 1mm 0 4mm; }
    .footer { margin-top: 8mm; padding-top: 2mm; border-top: 0.25mm solid #cfcfcf; font-size: 7.6pt; color: #555; }
    .muted { color: #666; }
    /* Preview in the wizard: page-like margins on screen. */
    @media screen { body { padding: 10mm 12mm; max-width: 210mm; margin: 0 auto; } }
</style>
</head>
<body>
    <div class="head">
        <div>
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @else
                <strong style="font-size: 13pt">{{ $d['company']['name'] }}</strong>
            @endif
        </div>
        <div class="company">
            <strong>{{ $d['company']['name'] }}</strong><br>
            {{ $d['company']['street'] }}@if ($d['company']['street'] && $d['company']['place']), @endif{{ $d['company']['place'] }}<br>
            {{ collect([$d['company']['phone'], $d['company']['email'], $d['company']['website']])->filter()->implode(' · ') }}
            @if ($d['company']['uid'] || $d['company']['vat_number'])
                <br>{{ $d['company']['vat_number'] ?: $d['company']['uid'] }}
            @endif
        </div>
    </div>

    <h1>@yield('title')</h1>
    <div class="docmeta">{{ __('No.') }} {{ $d['number'] }} · {{ \Illuminate\Support\Carbon::parse($d['date'])->format('d.m.Y') }}</div>

    @yield('content')

    <div class="footer">
        @if ($d['footer'])
            {{ $d['footer'] }}<br>
        @endif
        @if ($d['bank'])
            {{ __('Bank details') }}: {{ collect([$d['bank']['bank_name'], $d['bank']['holder'], 'IBAN '.$d['bank']['iban'], $d['bank']['bic'] ? 'BIC '.$d['bank']['bic'] : null])->filter()->implode(' · ') }}
        @endif
    </div>

    @if (! empty($d['evidence']))
        @include('documents.contracts._evidence')
    @endif
</body>
</html>
