{{-- Kaufvertrag Ankauf (the dealer buys a car). Rendered from the snapshot only; see ContractData::forPurchase. --}}
@extends('documents.contracts._layout')

@php
    use App\Support\Money;
    $date = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
    $p = $d['purchase'];
@endphp

@section('title', __('Purchase contract'))

@section('content')
    <div class="parties">
        <div>
            <div class="role">{{ __('Seller') }}</div>
            @if ($d['seller'])
                @include('documents.contracts._party', ['p' => $d['seller']])
            @else
                –
            @endif
        </div>
        <div>
            <div class="role">{{ __('Buyer') }}</div>
            <strong>{{ $d['company']['name'] }}</strong>
            @if ($d['company']['street'])<br>{{ $d['company']['street'] }}@endif
            @if ($d['company']['place'])<br>{{ $d['company']['place'] }}@endif
        </div>
    </div>

    <h2>{{ __('Vehicle') }}</h2>
    @include('documents.contracts._vehicle', ['v' => $d['vehicle']])

    <h2>{{ __('Price and payment') }}</h2>
    <table class="sum">
        <tr class="total"><td>{{ __('Purchase price') }}</td><td class="num">{{ Money::format($p['price_rp']) }}</td></tr>
        @if ($p['vat_shown_rp'])
            <tr><td class="muted">{{ __('of which VAT shown') }}</td><td class="num muted">{{ Money::format($p['vat_shown_rp']) }}</td></tr>
        @endif
        @if ($p['payoff_rp'])
            <tr><td>{{ __('Payoff to :party', ['party' => $p['payoff_party'] ?? __('the financing company')]) }}</td><td class="num">− {{ Money::format($p['payoff_rp']) }}</td></tr>
            <tr class="total"><td>{{ __('Paid to the seller') }}</td><td class="num">{{ Money::format($p['to_seller_rp']) }}</td></tr>
        @endif
    </table>
    <table class="facts" style="margin-top: 2mm">
        <tr>
            <td><span>{{ __('Contract date') }}</span>{{ $date($p['contract_on']) }}</td>
            <td><span>{{ __('Handover') }}</span>{{ $date($p['delivered_on']) }}</td>
            <td><span>{{ __('Seller type') }}</span>{{ $p['seller_kind'] }}</td>
            <td><span>{{ __('Purchase type') }}</span>{{ $p['purchase_type'] }}</td>
        </tr>
    </table>

    @if ($p['known_defects'] || $p['agreed_deliverables'] || $d['remarks'])
        <h2>{{ __('Remarks and agreements') }}</h2>
        <div class="box">
            @if ($p['known_defects'])<div class="pre"><strong>{{ __('Known defects') }}:</strong> {{ $p['known_defects'] }}</div>@endif
            @if ($p['agreed_deliverables'])<div class="pre"><strong>{{ __('Seller delivers') }}:</strong> {{ $p['agreed_deliverables'] }}</div>@endif
            @if ($d['remarks'])<div class="pre">{{ $d['remarks'] }}</div>@endif
        </div>
    @endif

    <h2>{{ __('Terms') }}</h2>
    <ol class="clauses">
        @foreach ($d['clauses'] as $clause)
            <li>{{ $clause }}</li>
        @endforeach
    </ol>

    @include('documents.contracts._signatures', ['left' => __('Seller'), 'right' => __('Buyer')])
@endsection
