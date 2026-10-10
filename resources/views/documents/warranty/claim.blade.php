{{-- Warranty claim report for the provider. --}}
@php
    use App\Support\Money;
    $vehicle = $warranty->stockCycle->vehicle;
    $km = fn (?int $v): string => $v === null ? '–' : number_format($v, 0, '.', "'").' km';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Warranty claim') }}</title>
<style>
    @page { size: A4; margin: 16mm 18mm; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; margin: 0; }
    h1 { font-size: 16pt; margin: 0 0 1mm; }
    h2 { font-size: 11pt; margin: 7mm 0 2mm; border-bottom: 0.3mm solid #999; padding-bottom: 1mm; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1.2mm 0; vertical-align: top; }
    td.label { width: 48mm; color: #555; }
    .text { white-space: pre-line; }
</style>
</head>
<body>
    <h1>{{ __('Warranty claim') }}</h1>
    <div class="muted">{{ $tenant->legal_name ?? $tenant->name }} · {{ __('to') }} {{ $warranty->product->provider?->displayName() ?? '–' }} · {{ now()->format('d.m.Y') }}</div>

    <h2>{{ __('Policy') }}</h2>
    <table>
        <tr><td class="label">{{ __('Policy number') }}</td><td>{{ $warranty->policy_number ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Product') }}</td><td>{{ $warranty->product->label() }}</td></tr>
        <tr><td class="label">{{ __('Valid') }}</td><td>{{ $warranty->starts_on?->format('d.m.Y') ?? '–' }} – {{ $warranty->ends_on?->format('d.m.Y') ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Customer') }}</td><td>{{ $warranty->sale?->buyer->displayName() ?? '–' }}</td></tr>
    </table>

    <h2>{{ __('Vehicle') }}</h2>
    <table>
        <tr><td class="label">{{ __('Vehicle') }}</td><td>{{ $vehicle->displayName() }}</td></tr>
        <tr><td class="label">{{ __('VIN') }}</td><td>{{ $vehicle->vin ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Stammnummer') }}</td><td>{{ $vehicle->stammnummer ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Mileage at start') }}</td><td>{{ $km($warranty->km_at_start) }}</td></tr>
    </table>

    <h2>{{ __('Damage') }}</h2>
    <table>
        <tr><td class="label">{{ __('Damage date') }}</td><td>{{ $claim->occurred_on->format('d.m.Y') }}</td></tr>
        <tr><td class="label">{{ __('Mileage') }}</td><td>{{ $km($claim->mileage) }}</td></tr>
        <tr><td class="label">{{ __('What happened') }}</td><td class="text">{{ $claim->description }}</td></tr>
        <tr><td class="label">{{ __('Diagnosis') }}</td><td class="text">{{ $claim->diagnosis ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Workshop') }}</td><td>{{ $claim->workshop?->displayName() ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Repair amount') }}</td><td>{{ $claim->amount_rp > 0 ? Money::format($claim->amount_rp) : '–' }}</td></tr>
    </table>
</body>
</html>
