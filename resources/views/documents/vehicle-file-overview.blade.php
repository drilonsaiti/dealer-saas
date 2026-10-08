@php
    use App\Support\Money;
    use App\Support\SwissFormat;
    $vehicle = $cycle->vehicle;
    $sale = $cycle->activeSale;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $cycle->title() }}</title>
<style>
    @page { size: A4; margin: 15mm; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 9.5pt; color: #1a1a1a; line-height: 1.35; }
    h1 { font-size: 16pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.4mm solid #1a1a1a; }
    .meta { color: #555; margin-bottom: 4mm; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 1.2mm 1.5mm; vertical-align: top; }
    th { font-weight: 600; color: #444; }
    .grid td { width: 25%; }
    .grid td span { display: block; color: #666; font-size: 8pt; }
    .list th { border-bottom: 0.3mm solid #999; font-size: 8.5pt; }
    .list td { border-bottom: 0.2mm solid #ddd; }
    .num { text-align: right; white-space: nowrap; }
    .mono { font-family: "Courier New", monospace; }
    .total td { font-weight: 700; border-top: 0.4mm solid #1a1a1a; }
    .badge { display: inline-block; padding: 0 1.5mm; border: 0.2mm solid #888; border-radius: 1mm; font-size: 8pt; }
</style>
</head>
<body>
    <h1>{{ $cycle->title() }}</h1>
    <div class="meta">{{ $tenant?->name }} · {{ __('Vehicle file') }} {{ $cycle->number ?? '–' }} · {{ __('Status') }}: {{ $cycle->status->getLabel() }} · {{ __('Exported on :date', ['date' => now()->format('d.m.Y H:i')]) }}</div>

    <h2>{{ __('Vehicle') }}</h2>
    <table class="grid">
        <tr>
            <td><span>{{ __('Stammnummer') }}</span><span class="mono" style="color:#1a1a1a;font-size:9.5pt">{{ $vehicle->formattedStammnummer() ?? '–' }}</span></td>
            <td><span>{{ __('VIN') }}</span>{{ $vehicle->vin ?? '–' }}</td>
            <td><span>{{ __('Plate') }}</span>{{ $vehicle->plate ?? '–' }}</td>
            <td><span>{{ __('First registration') }}</span>{{ SwissFormat::date($vehicle->first_registration_on) }}</td>
        </tr>
        <tr>
            <td><span>{{ __('Make') }} / {{ __('Model') }}</span>{{ $vehicle->displayName() }}</td>
            <td><span>{{ __('Fuel') }}</span>{{ $vehicle->fuel?->getLabel() ?? '–' }}</td>
            <td><span>{{ __('Power (kW)') }}</span>{{ $vehicle->power_kw ?? '–' }}</td>
            <td><span>{{ __('Exterior colour') }}</span>{{ $vehicle->color_exterior ?? '–' }}</td>
        </tr>
        <tr>
            <td><span>{{ __('Purchased') }}</span>{{ SwissFormat::date($cycle->purchased_on) }}</td>
            <td><span>{{ __('Sold') }}</span>{{ SwissFormat::date($cycle->sold_on) }}</td>
            <td><span>{{ __('Delivered') }}</span>{{ SwissFormat::date($cycle->delivered_on) }}</td>
            <td><span>{{ __('Days in stock') }}</span>{{ $cycle->daysInStock() ?? '–' }}</td>
        </tr>
        <tr>
            <td><span>{{ __('Mileage at purchase') }}</span>{{ SwissFormat::mileage($cycle->mileage_in) }}</td>
            <td><span>{{ __('Mileage at handover') }}</span>{{ SwissFormat::mileage($cycle->mileage_out) }}</td>
            <td><span>{{ __('List price') }}</span>{{ Money::format($cycle->list_price_rp) }}</td>
            <td><span>{{ __('File year') }}</span>{{ $cycle->file_year ?? '–' }}</td>
        </tr>
    </table>

    @if ($cycle->purchase)
        <h2>{{ __('Purchase') }}</h2>
        <table class="grid">
            <tr>
                <td><span>{{ __('Seller') }}</span>{{ $cycle->purchase->seller?->displayName() ?? '–' }}</td>
                <td><span>{{ __('Purchase type') }}</span>{{ $cycle->purchase->purchase_type->getLabel() }}</td>
                <td><span>{{ __('Purchase date') }}</span>{{ SwissFormat::date($cycle->purchase->contract_on) }}</td>
                <td><span>{{ __('Purchase price') }}</span>{{ Money::format($cycle->purchase->price_rp) }}</td>
            </tr>
            <tr>
                <td><span>{{ __('VAT situation') }}</span>{{ $cycle->purchase->vat_situation->getLabel() }}</td>
                <td><span>{{ __('Payment to seller') }}</span>{{ $cycle->purchase->payment_status->getLabel() }}</td>
                <td colspan="2"><span>{{ __('Known defects') }}</span>{{ $cycle->purchase->known_defects ?? '–' }}</td>
            </tr>
        </table>
    @endif

    @if ($sale)
        <h2>{{ __('Sale') }}</h2>
        <table class="grid">
            <tr>
                <td><span>{{ __('Buyer') }}</span>{{ $sale->buyer->displayName() }}</td>
                <td><span>{{ __('Status') }}</span>{{ $sale->status->getLabel() }}</td>
                <td><span>{{ __('Contract date') }}</span>{{ SwissFormat::date($sale->sale_on) }}</td>
                <td><span>{{ __('Payment') }}</span>{{ $sale->payment_type->getLabel() }}</td>
            </tr>
            <tr>
                <td><span>{{ __('Sale price') }}</span>{{ Money::format($sale->price_rp) }}</td>
                <td><span>{{ __('Discount') }}</span>{{ Money::format($sale->discount_rp) }}</td>
                <td><span>{{ __('Total incl. extras') }}</span>{{ Money::format($sale->totalRp()) }}</td>
                <td><span>{{ __('Balance to pay') }}</span>{{ Money::format($sale->balanceRp()) }}</td>
            </tr>
            @if ($sale->tradeIn)
                <tr>
                    <td colspan="2"><span>{{ __('Trade-in') }}</span>{{ $sale->tradeIn->vehicleName() }}</td>
                    <td><span>{{ __('Trade-in value') }}</span>{{ Money::format($sale->tradeIn->value_rp) }}</td>
                    <td><span>{{ __('Trade-in credit') }}</span>{{ Money::format($sale->tradeIn->credited_rp) }}</td>
                </tr>
            @endif
        </table>
    @endif

    <h2>{{ __('Costs') }}</h2>
    @if ($cycle->costs->isEmpty())
        <p>–</p>
    @else
        <table class="list">
            <tr><th>{{ __('Date') }}</th><th>{{ __('Category') }}</th><th>{{ __('Description') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Amount') }}</th></tr>
            @foreach ($cycle->costs->sortBy('incurred_on') as $cost)
                <tr>
                    <td>{{ SwissFormat::date($cost->incurred_on) }}</td>
                    <td>{{ $cost->category->name }}</td>
                    <td>{{ $cost->description }}</td>
                    <td>{{ $cost->status->getLabel() }}{{ $cost->is_estimate ? ' · '.__('Estimate') : '' }}</td>
                    <td class="num">{{ Money::format($cost->gross_rp) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="4">{{ __('Total') }}</td><td class="num">{{ Money::format((int) $cycle->costs->sum('gross_rp')) }}</td></tr>
        </table>
    @endif

    @if ($cycle->commitments->isNotEmpty())
        <h2>{{ __('Promises to customer') }}</h2>
        <table class="list">
            <tr><th>{{ __('What was promised') }}</th><th>{{ __('Done') }}</th><th class="num">{{ __('Estimated cost') }}</th></tr>
            @foreach ($cycle->commitments as $commitment)
                <tr>
                    <td>{{ $commitment->description }}</td>
                    <td>{{ $commitment->isDone() ? SwissFormat::date($commitment->done_at) : '–' }}</td>
                    <td class="num">{{ Money::format($commitment->estimated_cost_rp) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($margin)
        <h2>{{ __('Margin') }} <span class="badge">{{ $margin->isProvisional ? __('provisional') : __('confirmed') }}</span></h2>
        <table class="list">
            <tr><td>{{ __('Revenue') }}</td><td class="num">{{ Money::format($margin->revenueRp) }}</td></tr>
            <tr><td>{{ __('Purchase price') }}</td><td class="num">− {{ Money::format($margin->purchaseRp) }}</td></tr>
            <tr><td>{{ __('Costs') }}</td><td class="num">− {{ Money::format($margin->costsRp()) }}</td></tr>
            <tr class="total"><td>{{ __('Margin') }}</td><td class="num">{{ $margin->marginRp() === null ? '–' : Money::format($margin->marginRp()) }}</td></tr>
        </table>
    @endif

    <h2>{{ __('Required documents') }}</h2>
    <table class="list">
        @forelse ($checklist as $item)
            <tr><td>{{ $item['label'] }}</td><td>{{ $item['status']->getLabel() }}</td><td>{{ $item['note'] }}</td></tr>
        @empty
            <tr><td>–</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Documents') }}</h2>
    <table class="list">
        <tr><th>{{ __('Folder') }}</th><th>{{ __('Title') }}</th><th>{{ __('Date') }}</th><th>{{ __('Versions') }}</th></tr>
        @forelse ($documents as $document)
            <tr>
                <td>{{ $document->category->folder_group->folderName() }}</td>
                <td>{{ $document->title }}</td>
                <td>{{ SwissFormat::date($document->document_on) }}</td>
                <td>{{ $document->versions->count() }}</td>
            </tr>
        @empty
            <tr><td colspan="4">–</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Status history') }}</h2>
    <table class="list">
        @foreach ($history->sortBy('created_at') as $entry)
            <tr>
                <td>{{ $entry->created_at->format('d.m.Y H:i') }}</td>
                <td>{{ \App\Domain\Vehicles\Enums\StockCycleStatus::tryFrom($entry->to_status)?->getLabel() ?? $entry->to_status }}</td>
                <td>{{ $entry->user?->name ?? __('System') }}</td>
                <td>{{ $entry->reason }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
