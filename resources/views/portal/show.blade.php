@php
    use App\Domain\Sales\Enums\SaleStatus;
    use App\Support\Money;
    $brand = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tenant?->brand_color) ? $tenant->brand_color : '#18181b';
    $dealer = $tenant?->legal_name ?: $tenant?->name;
    $vehicle = $sale->stockCycle->vehicle;
    $photo = $content->photo();
    $order = [SaleStatus::Reserved->value => 1, SaleStatus::Contracted->value => 2, SaleStatus::Invoiced->value => 3, SaleStatus::Delivered->value => 4];
    $reached = $order[$sale->status->value] ?? 0;
    $steps = [
        1 => __('Reserved'),
        2 => __('Contract signed'),
        3 => __('Invoiced'),
        4 => $sale->delivered_on ? __('Handed over :date', ['date' => $sale->delivered_on->format('d.m.Y')]) : ($sale->planned_handover_on ? __('Handover planned :date', ['date' => $sale->planned_handover_on->format('d.m.Y')]) : __('Handover')),
    ];
    $url = fn ($document) => route('portal.download', ['token' => $token, 'document' => $document->getKey()]);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $vehicle->displayName() }} – {{ $dealer }}</title>
<style>
    :root { --brand: {{ $brand }}; --ink: #18181b; --muted: #52525b; --line: #e4e4e7; --bg: #f4f4f5; --ok: #15803d; --err: #b91c1c; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 -apple-system, "Helvetica Neue", Arial, sans-serif; }
    header { background: #fff; border-bottom: 3px solid var(--brand); padding: 14px 16px; }
    header .wrap { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .wrap { max-width: 920px; margin: 0 auto; }
    main { padding: 20px 16px 48px; }
    h1 { font-size: 24px; margin: 0 0 2px; }
    h2 { font-size: 17px; margin: 0 0 10px; }
    .muted { color: var(--muted); }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-top: 16px; }
    .hero { display: grid; grid-template-columns: 240px 1fr; gap: 18px; align-items: center; }
    .hero img { width: 100%; border-radius: 8px; aspect-ratio: 4/3; object-fit: cover; background: var(--bg); }
    .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 4px; }
    .steps div { padding: 8px 10px; border-radius: 8px; background: var(--bg); color: var(--muted); font-size: 14px; }
    .steps div.done { background: #dcfce7; color: var(--ok); font-weight: 600; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    th { text-align: left; color: var(--muted); font-weight: 600; font-size: 13px; border-bottom: 1px solid var(--line); padding: 6px 4px; }
    td { padding: 8px 4px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .open { color: var(--err); font-weight: 600; }
    .paid { color: var(--ok); }
    a { color: var(--ink); }
    label { display: block; font-weight: 600; margin: 12px 0 6px; }
    input[type=text], input[type=file] { width: 100%; padding: 9px 10px; font-size: 15px; border: 1px solid #d4d4d8; border-radius: 8px; background: #fff; }
    button { background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 11px 18px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 12px; }
    .notice { padding: 12px 14px; border-radius: 8px; margin-top: 16px; }
    .notice.ok { background: #dcfce7; color: var(--ok); }
    .notice.err { background: #fee2e2; color: var(--err); }
    @media (max-width: 640px) { .hero { grid-template-columns: 1fr; } .steps { grid-template-columns: repeat(2, 1fr); } }
</style>
</head>
<body>
<header>
    <div class="wrap">
        <strong>{{ $dealer }}</strong>
        <span class="muted" style="font-size: 14px">{{ __('Your customer area') }}</span>
    </div>
</header>
<main>
    <div class="wrap">
        @if (session('status'))
            <div class="notice ok">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice err">{{ $errors->first() }}</div>
        @endif

        <div class="card {{ $photo ? 'hero' : '' }}">
            @if ($photo)
                <img src="{{ $url($photo) }}" alt="{{ $vehicle->displayName() }}">
            @endif
            <div>
                <h1>{{ $vehicle->displayName() }}</h1>
                <div class="muted">{{ __('For :name', ['name' => $sale->buyer->displayName()]) }}</div>
                <div class="steps" style="margin-top:14px">
                    @foreach ($steps as $i => $label)
                        <div class="{{ $reached >= $i ? 'done' : '' }}">{{ $label }}</div>
                    @endforeach
                </div>
            </div>
        </div>

        @php $invoices = $content->invoices(); @endphp
        @if ($invoices->isNotEmpty())
            <div class="card">
                <h2>{{ __('Invoices') }}</h2>
                <table>
                    <tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th class="num">{{ __('Amount') }}</th><th class="num">{{ __('Open') }}</th><th></th></tr>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->number }}</td>
                            <td>{{ $invoice->issued_on?->format('d.m.Y') }}</td>
                            <td class="num">{{ Money::format($invoice->total_rp) }}</td>
                            <td class="num {{ $invoice->openRp() > 0 ? 'open' : 'paid' }}">{{ $invoice->openRp() > 0 ? Money::format($invoice->openRp()) : __('paid') }}</td>
                            <td class="num">@if ($invoice->document_id)<a href="{{ route('portal.download', ['token' => $token, 'document' => $invoice->document_id]) }}">PDF</a>@endif</td>
                        </tr>
                    @endforeach
                </table>
                @if ($invoices->sum(fn ($i) => $i->openRp()) > 0)
                    <p class="muted" style="font-size:14px">{{ __('Please pay with the QR bill in the PDF.') }}</p>
                @endif
            </div>
        @endif

        @php $warranties = $content->warranties(); @endphp
        @if ($warranties->isNotEmpty())
            <div class="card">
                <h2>{{ __('Warranty') }}</h2>
                @foreach ($warranties as $warranty)
                    <p style="margin:0 0 6px">
                        <strong>{{ $warranty->product->label() }}</strong>
                        @if ($warranty->policy_number) · {{ __('Policy :number', ['number' => $warranty->policy_number]) }} @endif
                        <br><span class="muted">
                            {{ $warranty->ends_on ? __('valid until :date', ['date' => $warranty->ends_on->format('d.m.Y')]) : __('starts at handover, :months months', ['months' => $warranty->duration_months]) }}
                            @if ($warranty->kmUntil()) · {{ __('up to :km km', ['km' => number_format($warranty->kmUntil(), 0, '.', "'")]) }} @endif
                        </span>
                    </p>
                @endforeach
                <p class="muted" style="font-size:14px;margin-bottom:0">{{ __('In case of damage, please contact us before any repair.') }}</p>
            </div>
        @endif

        <div class="card">
            <h2>{{ __('Documents') }}</h2>
            @php $documents = $content->documents(); @endphp
            @if ($documents->isEmpty())
                <p class="muted" style="margin:0">{{ __('No documents yet.') }}</p>
            @else
                <table>
                    @foreach ($documents as $document)
                        <tr>
                            <td>{{ $document->title }}<br><span class="muted" style="font-size:13px">{{ $document->category->name }} · {{ $document->created_at->format('d.m.Y') }}</span></td>
                            <td class="num"><a href="{{ $url($document) }}">{{ __('Download') }}</a></td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h2>{{ __('Send us a document') }}</h2>
            <p class="muted" style="margin:0">{{ __('For example your ID, the leasing approval or the insurance confirmation (PDF or photo, at most 20 MB).') }}</p>
            <form method="post" action="{{ route('portal.upload', ['token' => $token]) }}" enctype="multipart/form-data">
                @csrf
                <label for="note">{{ __('What is it?') }}</label>
                <input type="text" id="note" name="note" maxlength="200" placeholder="{{ __('e.g. insurance confirmation') }}">
                <label for="file">{{ __('File to send') }}</label>
                <input type="file" id="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.heic">
                <button type="submit">{{ __('Send') }}</button>
            </form>
        </div>

        <p class="muted" style="font-size:13px;margin-top:20px">
            {{ implode(' · ', array_filter([$dealer, trim(($tenant?->street ?? '').', '.($tenant?->zip ?? '').' '.($tenant?->city ?? ''), ', '), $tenant?->phone, $tenant?->email])) }}<br>
            {{ __('This page is personal. The link is valid until :date.', ['date' => $link->expires_at->format('d.m.Y')]) }}
        </p>
    </div>
</main>
</body>
</html>
