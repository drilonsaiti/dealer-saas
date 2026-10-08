@php
    use App\Support\SwissFormat;
    $date = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
@endphp
<table class="facts">
    <tr>
        <td colspan="2"><span>{{ __('Make / model') }}</span><strong>{{ $v['name'] }}</strong></td>
        <td><span>{{ __('Body type') }}</span>{{ $v['body_type'] ?? '–' }}</td>
        <td><span>{{ __('Mileage') }}</span>{{ SwissFormat::mileage($v['mileage']) }}</td>
    </tr>
    <tr>
        <td><span>{{ __('Stammnummer') }}</span>{{ $v['stammnummer'] ?? '–' }}</td>
        <td colspan="2"><span>{{ __('VIN') }}</span>{{ $v['vin'] ?? '–' }}</td>
        <td><span>{{ __('Type approval') }}</span>{{ $v['type_approval'] ?? '–' }}</td>
    </tr>
    <tr>
        <td><span>{{ __('First registration') }}</span>{{ $date($v['first_registration_on']) }}</td>
        <td><span>{{ __('Last MFK') }}</span>{{ $date($v['mfk_last_on']) }}</td>
        <td><span>{{ __('Power') }}</span>{{ $v['power_kw'] ? $v['power_kw'].' kW / '.$v['power_ps'].' PS' : '–' }}</td>
        <td><span>{{ __('Engine') }}</span>{{ collect([$v['displacement_cc'] ? SwissFormat::number($v['displacement_cc']).' cm³' : null, $v['fuel']])->filter()->implode(', ') ?: '–' }}</td>
    </tr>
    <tr>
        <td><span>{{ __('Exterior colour') }}</span>{{ $v['color_exterior'] ?? '–' }}</td>
        <td><span>{{ __('Transmission') }}</span>{{ $v['transmission'] ?? '–' }}</td>
        <td><span>{{ __('Curb / total weight') }}</span>{{ $v['curb_weight_kg'] || $v['total_weight_kg'] ? collect([$v['curb_weight_kg'], $v['total_weight_kg']])->map(fn ($kg) => $kg ? SwissFormat::number($kg).' kg' : '–')->implode(' / ') : '–' }}</td>
        <td><span>{{ __('Plate') }}</span>{{ $v['plate'] ?? '–' }}</td>
    </tr>
</table>
