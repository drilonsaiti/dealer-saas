{{-- Closed VAT return: figures per ESTV form field (to type in by hand if needed), net tax per rate, every entry. --}}
@php
    use App\Support\Money;
    $fields = $f['fields'];
    $rateText = fn ($v): string => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.').' %';
    $rows = [
        200 => __('Total consideration agreed or received, incl. exempt and excluded supplies'),
        220 => __('Exempt supplies abroad (exports)'),
        221 => __('Supplies provided abroad'),
        225 => __('Transfers under the notification procedure'),
        230 => __('Supplies excluded from VAT'),
        235 => __('Reductions of consideration (credit notes, discounts)'),
        280 => __('Other deductions'),
        289 => __('Total deductions'),
        299 => __('Taxable total turnover'),
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('VAT return :period', ['period' => $period->label()]) }}</title>
<style>
    @page { size: A4; margin: 14mm 16mm 16mm 18mm; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 9pt; color: #1a1a1a; line-height: 1.4; margin: 0; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 7.8pt; color: #555; font-weight: 600; border-bottom: 0.3mm solid #999; padding: 1.2mm 1.5mm; }
    td { padding: 1.2mm 1.5mm; border-bottom: 0.2mm solid #e2e2e2; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .field { width: 14mm; font-weight: 600; }
    .sum td { font-weight: 700; border-top: 0.3mm solid #1a1a1a; }
    .payable td { font-weight: 700; font-size: 11pt; border-top: 0.5mm solid #1a1a1a; }
    .box { border: 0.3mm solid #c9c9c9; border-radius: 1.5mm; padding: 2.5mm 3mm; margin-top: 4mm; }
    .small { font-size: 7.6pt; }
</style>
</head>
<body>
    <h1>{{ __('VAT return :period', ['period' => $period->label()]) }}</h1>
    <div class="muted">
        {{ $tenant?->legal_name ?: $tenant?->name }} · {{ $tenant?->uid ?: $tenant?->vat_number }} ·
        {{ \App\Domain\Vat\Enums\VatMethod::from($f['method'])->getLabel() }} ·
        {{ \App\Domain\Vat\Enums\VatBasis::from($f['basis'])->getLabel() }}
    </div>
    @if ($period->isCorrection())
        <div class="box">{{ __('Correction of the return :period: it replaces the return submitted before and contains the full amounts.', ['period' => $period->starts_on->format('d.m.Y').' – '.$period->ends_on->format('d.m.Y')]) }}</div>
    @endif

    <h2>{{ __('Turnover') }}</h2>
    <table>
        <tr><th>{{ __('Field') }}</th><th></th><th class="num">CHF</th></tr>
        @foreach ($rows as $field => $label)
            <tr @class(['sum' => in_array($field, [289, 299], true)])>
                <td class="field">{{ $field }}</td>
                <td>{{ $label }}</td>
                <td class="num">{{ Money::format((int) ($fields[$field] ?? 0), false) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('Tax calculation (net tax rates)') }}</h2>
    <table>
        <tr><th>{{ __('Activity') }}</th><th>{{ __('ESTV code') }}</th><th class="num">{{ __('Rate') }}</th><th class="num">{{ __('Turnover') }}</th><th class="num">{{ __('Tax') }}</th></tr>
        @forelse ($f['rates'] as $rate)
            <tr>
                <td>{{ $rate['activity'][app()->getLocale()] ?? collect($rate['activity'])->first() }}</td>
                <td>{{ $rate['activity_code'] ?? '–' }}</td>
                <td class="num">{{ $rateText($rate['rate']) }}</td>
                <td class="num">{{ Money::format((int) $rate['turnover_rp'], false) }}</td>
                <td class="num">{{ Money::format((int) $rate['tax_rp'], false) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('No taxable turnover.') }}</td></tr>
        @endforelse
        <tr class="payable">
            <td>500</td>
            <td colspan="3">{{ __('Amount payable to the ESTV') }}</td>
            <td class="num">{{ Money::format((int) $f['payable_rp'], false) }}</td>
        </tr>
    </table>
    <p class="small muted">
        {{ __('VAT shown on the invoices (information, not owed under the net tax rate method): :amount.', ['amount' => Money::format((int) $f['legal_vat_rp'])]) }}
        {{ __('Calculated on :date with the rules :rules.', ['date' => \Illuminate\Support\Carbon::parse($f['calculated_at'])->format('d.m.Y H:i'), 'rules' => implode(', ', $f['rule_versions']) ?: '–']) }}
    </p>

    <h2>{{ __('Entries') }}</h2>
    <table class="small">
        <tr><th>{{ __('Date') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Vehicle') }}</th><th>{{ __('Field') }}</th><th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Tax') }}</th></tr>
        @foreach ($events as $event)
            @continue(! $event->counts())
            <tr>
                <td>{{ $event->event_on->format('d.m.Y') }}{{ $event->late ? ' *' : '' }}</td>
                <td>{{ $event->invoice?->number }}</td>
                <td>{{ $event->invoice?->stockCycle?->number }}</td>
                <td>{{ $event->field }}</td>
                <td class="num">{{ Money::format($event->base_rp, false) }}</td>
                <td class="num">{{ Money::format($event->tax_rp, false) }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
