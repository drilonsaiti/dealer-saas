{{-- Registration of a warranty with the provider (sent by e-mail when there is no API). --}}
@php
    use App\Support\Money;
    $vehicle = $warranty->stockCycle->vehicle;
    $buyer = $warranty->sale?->buyer;
    $km = fn (?int $v): string => $v === null ? '–' : number_format($v, 0, '.', "'").' km';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Warranty registration') }}</title>
<style>
    @page { size: A4; margin: 16mm 18mm; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; margin: 0; }
    h1 { font-size: 16pt; margin: 0 0 1mm; }
    h2 { font-size: 11pt; margin: 7mm 0 2mm; border-bottom: 0.3mm solid #999; padding-bottom: 1mm; }
    .muted { color: #666; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1.2mm 0; vertical-align: top; }
    td.label { width: 48mm; color: #555; }
    .sign { margin-top: 18mm; display: flex; gap: 20mm; }
    .sign div { flex: 1; border-top: 0.3mm solid #1a1a1a; padding-top: 1.5mm; font-size: 8.5pt; color: #555; }
</style>
</head>
<body>
    <h1>{{ __('Warranty registration') }}</h1>
    <div class="muted">{{ $tenant->legal_name ?? $tenant->name }} · {{ __('to') }} {{ $warranty->product->provider?->displayName() ?? '–' }} · {{ now()->format('d.m.Y') }}</div>

    <h2>{{ __('Warranty') }}</h2>
    <table>
        <tr><td class="label">{{ __('Product') }}</td><td>{{ $warranty->product->label() }}</td></tr>
        <tr><td class="label">{{ __('Duration') }}</td><td>{{ $warranty->duration_months }} {{ __('months') }}</td></tr>
        <tr><td class="label">{{ __('Start') }}</td><td>{{ $warranty->starts_on?->format('d.m.Y') ?? __('at handover') }}</td></tr>
        <tr><td class="label">{{ __('km limit') }}</td><td>{{ $km($warranty->km_limit) }}</td></tr>
        <tr><td class="label">{{ __('Coverage limit') }}</td><td>{{ $warranty->coverage_limit_rp !== null ? Money::format($warranty->coverage_limit_rp) : '–' }}</td></tr>
        <tr><td class="label">{{ __('Deductible') }}</td><td>{{ Money::format($warranty->deductible_rp) }}</td></tr>
        @if ($warranty->policy_number)
            <tr><td class="label">{{ __('Policy number') }}</td><td>{{ $warranty->policy_number }}</td></tr>
        @endif
    </table>

    <h2>{{ __('Vehicle') }}</h2>
    <table>
        <tr><td class="label">{{ __('Vehicle') }}</td><td>{{ $vehicle->displayName() }}</td></tr>
        <tr><td class="label">{{ __('VIN') }}</td><td>{{ $vehicle->vin ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Stammnummer') }}</td><td>{{ $vehicle->stammnummer ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('First registration') }}</td><td>{{ $vehicle->first_registration_on?->format('d.m.Y') ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Mileage') }}</td><td>{{ $km($warranty->km_at_start ?? $warranty->stockCycle->mileage_out ?? $warranty->stockCycle->mileage_in) }}</td></tr>
        <tr><td class="label">{{ __('Power') }}</td><td>{{ $vehicle->power_kw !== null ? $vehicle->power_kw.' kW' : '–' }}</td></tr>
    </table>

    <h2>{{ __('Buyer') }}</h2>
    <table>
        <tr><td class="label">{{ __('Name') }}</td><td>{{ $buyer?->displayName() ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Address') }}</td><td>{{ $buyer === null ? '–' : trim(($buyer->street ?? '').', '.($buyer->zip ?? '').' '.($buyer->city ?? ''), ', ') }}</td></tr>
        <tr><td class="label">{{ __('E-mail') }}</td><td>{{ $buyer?->email ?? '–' }}</td></tr>
        <tr><td class="label">{{ __('Sale date') }}</td><td>{{ $warranty->sale?->sale_on?->format('d.m.Y') ?? '–' }}</td></tr>
    </table>

    <div class="sign">
        <div>{{ __('Place, date') }}</div>
        <div>{{ __('Signature dealer') }}</div>
    </div>
</body>
</html>
