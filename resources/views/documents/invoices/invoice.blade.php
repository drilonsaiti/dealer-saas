{{-- Invoice / credit note, rendered from the snapshot only (InvoiceData). The QR bill sits at the bottom of its own page. --}}
@php
    use App\Support\Money;
    $date = fn (?string $v): string => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y') : '–';
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['company']['brand_color'] ?? '')) ? $d['company']['brand_color'] : '#1a1a1a';
    $r = $d['recipient'];
    $isCredit = $d['type'] === 'credit_note';
    $rate = fn (float $v): string => $v > 0 ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.').' %' : '–';
@endphp
<!doctype html>
<html lang="{{ $d['locale'] }}">
<head>
<meta charset="utf-8">
<title>{{ $d['title'] }} {{ $d['number'] }}</title>
<style>
    @page { size: A4; margin: 14mm 16mm 18mm 20mm; }
    @page qr { margin: 0; }
    * { box-sizing: border-box; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 9.2pt; color: #1a1a1a; line-height: 1.4; margin: 0; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 0.6mm solid {{ $accent }}; padding-bottom: 3mm; }
    .head img { max-height: 16mm; max-width: 60mm; }
    .head .company { text-align: right; font-size: 8.2pt; color: #444; }
    .address { margin: 12mm 0 0 98mm; min-height: 30mm; font-size: 10pt; }
    .address .sender { font-size: 6.5pt; color: #666; border-bottom: 0.2mm solid #999; margin-bottom: 2mm; padding-bottom: 0.5mm; }
    h1 { font-size: 16pt; margin: 6mm 0 3mm; }
    .meta { display: flex; flex-wrap: wrap; gap: 2mm 8mm; margin-bottom: 5mm; }
    .meta div span { display: block; font-size: 7.6pt; color: #666; }
    table { width: 100%; border-collapse: collapse; }
    .lines th { text-align: left; font-size: 7.8pt; color: #555; font-weight: 600; border-bottom: 0.3mm solid #999; padding: 1.2mm 1.5mm; }
    .lines td { padding: 1.4mm 1.5mm; border-bottom: 0.2mm solid #e2e2e2; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .sum { width: 95mm; margin: 4mm 0 0 auto; }
    .sum td { padding: 1mm 1.5mm; }
    .sum .total td { font-weight: 700; border-top: 0.4mm solid #1a1a1a; font-size: 10.5pt; }
    .muted { color: #666; }
    .notes { margin-top: 6mm; white-space: pre-line; }
    .terms { margin-top: 6mm; }
    .footer { margin-top: 10mm; padding-top: 2mm; border-top: 0.25mm solid #cfcfcf; font-size: 7.6pt; color: #555; }
    .qr-page { page: qr; break-before: page; position: relative; width: 210mm; height: 296mm; overflow: hidden; }
    .qr-page .qr-head { padding: 14mm 16mm 0 20mm; font-size: 9pt; }
    .qr-page .qr-slot { position: absolute; left: 0; bottom: 0; }
</style>
</head>
<body>
    <div class="head">
        <div>
            @if ($logo)<img src="{{ $logo }}" alt="">@else<strong style="font-size: 13pt">{{ $d['company']['name'] }}</strong>@endif
        </div>
        <div class="company">
            <strong style="color: #1a1a1a">{{ $d['company']['name'] }}</strong><br>
            {{ collect([$d['company']['street'], trim($d['company']['zip'].' '.$d['company']['city'])])->filter()->implode(', ') }}<br>
            {{ collect([$d['company']['phone'], $d['company']['email'], $d['company']['website']])->filter()->implode(' · ') }}
            @if ($d['company']['vat_number'] || $d['company']['uid'])<br>{{ $d['company']['vat_number'] ?: $d['company']['uid'] }}@endif
        </div>
    </div>

    <div class="address">
        <div class="sender">{{ $d['company']['name'] }}, {{ collect([$d['company']['street'], trim($d['company']['zip'].' '.$d['company']['city'])])->filter()->implode(', ') }}</div>
        <strong>{{ $r['name'] }}</strong>
        @if ($r['contact'] ?? null)<br>{{ $r['contact'] }}@endif
        @if ($r['street'] ?? null)<br>{{ $r['street'] }}@endif
        <br>{{ ($r['country'] ?? 'CH') !== 'CH' ? $r['country'].'-' : '' }}{{ $r['zip'] }} {{ $r['city'] }}
    </div>

    <h1>{{ $d['title'] }} {{ $d['number'] }}</h1>
    <div class="meta">
        <div><span>{{ __('Date') }}</span>{{ $date($d['issued_on']) }}</div>
        @if (! $isCredit)<div><span>{{ __('Payable by') }}</span>{{ $date($d['due_on']) }}</div>@endif
        @if ($d['service_on'])<div><span>{{ __('Date of supply') }}</span>{{ $date($d['service_on']) }}</div>@endif
        @if ($d['credits'])<div><span>{{ __('Corrects invoice') }}</span>{{ $d['credits']['number'] }} {{ __('of :date', ['date' => $date($d['credits']['issued_on'])]) }}</div>@endif
        @if ($d['vehicle'])<div><span>{{ __('Vehicle') }}</span>{{ $d['vehicle']['name'] }}@if ($d['vehicle']['stammnummer']) · {{ $d['vehicle']['stammnummer'] }}@endif</div>@endif
        @if ($r['uid'] ?? null)<div><span>{{ __('Your UID') }}</span>{{ $r['uid'] }}</div>@endif
    </div>

    <table class="lines">
        <tr>
            <th style="width: 8mm">{{ __('Pos.') }}</th>
            <th>{{ __('Description') }}</th>
            <th class="num">{{ __('Qty') }}</th>
            <th class="num">{{ __('Price') }}</th>
            <th class="num">{{ __('VAT') }}</th>
            <th class="num">{{ __('Amount') }}</th>
        </tr>
        @foreach ($d['lines'] as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line['qty'], 2, '.', ''), '0'), '.') }}</td>
                <td class="num">{{ Money::format($line['unit_price_rp'], false) }}</td>
                <td class="num">{{ $rate($line['vat_rate']) }}</td>
                <td class="num">{{ Money::format($line['total_rp'], false) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="sum">
        <tr class="total"><td>{{ $d['vat_rp'] !== 0 ? __('Total incl. VAT') : __('Total') }}</td><td class="num">{{ Money::format($d['total_rp']) }}</td></tr>
        @foreach ($d['vat_by_rate'] as $vat)
            <tr class="muted"><td>{{ __('VAT :rate % on :base', ['rate' => $vat['rate'], 'base' => Money::format($vat['base_rp'])]) }}</td><td class="num">{{ Money::format($vat['vat_rp']) }}</td></tr>
        @endforeach
        @if (! $isCredit && $d['payments_rp'] > 0)
            <tr><td>{{ __('Already paid / credited') }}</td><td class="num">− {{ Money::format($d['payments_rp']) }}</td></tr>
            <tr class="total"><td>{{ __('Amount due') }}</td><td class="num">{{ Money::format($d['open_rp']) }}</td></tr>
        @endif
    </table>

    @if ($d['vat_rp'] === 0 && ! $d['company']['vat_number'])
        <p class="muted">{{ __('Not subject to VAT.') }}</p>
    @endif

    @if ($d['notes'])<div class="notes">{{ $d['notes'] }}</div>@endif

    <div class="terms">
        @if ($isCredit)
            {{ __('This credit note corrects the invoice above. Amounts already paid are refunded or offset.') }}
        @elseif ($d['open_rp'] > 0)
            {{ __('Please pay :amount by :date with the QR bill below.', ['amount' => Money::format($d['open_rp']), 'date' => $date($d['due_on'])]) }}
        @else
            {{ __('This invoice is paid. Thank you.') }}
        @endif
    </div>

    <div class="footer">
        @if ($d['bank'])
            {{ __('Bank details') }}: {{ collect([$d['bank']['bank_name'], $d['bank']['holder'], 'IBAN '.$d['bank']['iban'], $d['bank']['bic'] ? 'BIC '.$d['bank']['bic'] : null])->filter()->implode(' · ') }}
        @endif
    </div>

    @if ($qrHtml)
        <div class="qr-page">
            <div class="qr-head"><strong>{{ $d['title'] }} {{ $d['number'] }}</strong> · {{ $d['company']['name'] }} · {{ $r['name'] }}</div>
            <div class="qr-slot">{!! $qrHtml !!}</div>
        </div>
    @endif
</body>
</html>
