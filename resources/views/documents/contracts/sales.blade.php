{{-- Kaufvertrag (sale to a customer). Rendered from the snapshot only; see ContractData::forSale. --}}
@extends('documents.contracts._layout')

@php
    use App\Support\Money;
    use App\Support\SwissFormat;
    $date = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('d.m.Y') : '–';
    $v = $d['vehicle'];
    $s = $d['sale'];
    $t = $d['trade_in'];
    $openCommitments = collect($d['commitments'])->reject(fn (array $c): bool => $c['done']);
@endphp

@section('title', __('Sales contract'))

@section('content')
    <div class="parties">
        <div>
            <div class="role">{{ __('Seller') }}</div>
            <strong>{{ $d['company']['name'] }}</strong>
            @if ($d['company']['street'])<br>{{ $d['company']['street'] }}@endif
            @if ($d['company']['place'])<br>{{ $d['company']['place'] }}@endif
        </div>
        <div>
            <div class="role">{{ __('Buyer') }}</div>
            @include('documents.contracts._party', ['p' => $d['buyer']])
        </div>
        @if ($d['holder'])
            <div>
                <div class="role">{{ __('Vehicle holder') }}</div>
                @include('documents.contracts._party', ['p' => $d['holder']])
            </div>
        @endif
    </div>

    <h2>{{ __('Vehicle') }}</h2>
    @include('documents.contracts._vehicle', ['v' => $v])

    @if ($s['items'] !== [])
        <h2>{{ __('Services and accessories') }}</h2>
        <table class="lines">
            <tr><th>{{ __('Description') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Price') }}</th><th class="num">{{ __('Total') }}</th></tr>
            @foreach ($s['items'] as $item)
                <tr>
                    <td>{{ $item['description'] }} <span class="muted">· {{ $item['kind'] }}</span></td>
                    <td class="num">{{ rtrim(rtrim(number_format($item['qty'], 2, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ Money::format($item['unit_price_rp']) }}</td>
                    <td class="num">{{ Money::format($item['total_rp']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($t)
        <h2>{{ __('Trade-in vehicle') }}</h2>
        <table class="facts">
            <tr>
                <td><span>{{ __('Vehicle') }}</span>{{ $t['vehicle'] }}</td>
                <td><span>{{ __('Stammnummer') }}</span>{{ $t['stammnummer'] ?? '–' }}</td>
                <td><span>{{ __('First registration') }}</span>{{ $date($t['first_registration_on']) }}</td>
                <td><span>{{ __('Mileage') }}</span>{{ SwissFormat::mileage($t['mileage']) }}</td>
            </tr>
        </table>
    @endif

    <h2>{{ __('Price and payment') }}</h2>
    <table class="sum">
        <tr><td>{{ __('Vehicle price') }}</td><td class="num">{{ Money::format($s['price_rp']) }}</td></tr>
        @if ($s['discount_rp'] > 0)
            <tr><td>{{ __('Discount') }}</td><td class="num">− {{ Money::format($s['discount_rp']) }}</td></tr>
        @endif
        @if ($s['items'] !== [])
            <tr><td>{{ __('Services and accessories') }}</td><td class="num">{{ Money::format(collect($s['items'])->sum('total_rp')) }}</td></tr>
        @endif
        <tr class="total"><td>{{ $d['company']['vat_number'] ? __('Total price incl. VAT') : __('Total price') }}</td><td class="num">{{ Money::format($s['total_rp']) }}</td></tr>
        @if ($t)
            <tr>
                <td>
                    {{ __('Trade-in credit') }}
                    @if ($t['payoff_rp'] > 0 || $t['customer_payout_rp'] > 0 || $t['customer_topup_rp'] > 0)
                        <span class="muted">({{ collect([
                            __('value').' '.Money::format($t['value_rp']),
                            $t['payoff_rp'] > 0 ? __('payoff').' '.Money::format($t['payoff_rp']) : null,
                            $t['customer_payout_rp'] > 0 ? __('paid out to the customer').' '.Money::format($t['customer_payout_rp']) : null,
                            $t['customer_topup_rp'] > 0 ? __('top-up by the customer').' '.Money::format($t['customer_topup_rp']) : null,
                        ])->filter()->implode(', ') }})</span>
                    @endif
                </td>
                <td class="num">{{ $t['credited_rp'] >= 0 ? '−' : '+' }} {{ Money::format(abs($t['credited_rp'])) }}</td>
            </tr>
        @endif
        @if ($s['deposit_rp'] > 0)
            <tr><td>{{ __('Deposit') }}</td><td class="num">− {{ Money::format($s['deposit_rp']) }}</td></tr>
        @endif
        <tr class="total"><td>{{ __('Amount due') }}</td><td class="num">{{ Money::format($s['balance_rp']) }}</td></tr>
    </table>
    <table class="facts" style="margin-top: 2mm">
        <tr>
            <td><span>{{ __('Payment') }}</span>{{ $s['payment_type_label'] }}</td>
            <td><span>{{ __('Sale date') }}</span>{{ $date($s['sale_on']) }}</td>
            <td><span>{{ __('Planned handover') }}</span>{{ $date($s['planned_handover_on']) }}</td>
            <td>
                @if ($d['invoice_recipient'])
                    <span>{{ __('Invoice to') }}</span>{{ $d['invoice_recipient']['name'] }}
                @endif
            </td>
        </tr>
    </table>

    @if ($openCommitments->isNotEmpty() || $d['remarks'])
        <h2>{{ __('Remarks and agreements') }}</h2>
        <div class="box">
            @if ($openCommitments->isNotEmpty())
                <ul class="checks">
                    @foreach ($openCommitments as $commitment)
                        <li>{{ $commitment['description'] }}@if ($commitment['due_on']) ({{ __('by :date', ['date' => $date($commitment['due_on'])]) }})@endif</li>
                    @endforeach
                </ul>
            @endif
            @if ($d['remarks'])
                <div class="pre" @if ($openCommitments->isNotEmpty()) style="margin-top: 2mm" @endif>{{ $d['remarks'] }}</div>
            @endif
        </div>
    @endif

    <div class="page-break"></div>
    <h2>{{ __('Terms') }}</h2>
    <ol class="clauses">
        @foreach ($d['clauses'] as $clause)
            <li>{{ $clause }}</li>
        @endforeach
    </ol>

    @include('documents.contracts._signatures', ['left' => __('Seller'), 'right' => __('Buyer')])
@endsection
