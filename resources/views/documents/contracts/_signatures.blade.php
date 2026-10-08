{{-- Signature fields. The e-signature step fills them from $d['signatures'] (image, place, date). --}}
<div class="signatures">
    @foreach (['left' => $left, 'right' => $right] as $side => $label)
        @php $signature = $d['signatures'][$side] ?? null; @endphp
        <div data-signer="{{ $side }}">
            <div class="sig-line">{{ $signature['place_date'] ?? '' }}</div>
            <div class="sig-label">{{ __('Place and date') }}</div>
            <div class="sig-line sign">@if ($signature['image'] ?? null)<img src="{{ $signature['image'] }}" alt="">@endif</div>
            <div class="sig-label">{{ __('Signature') }} {{ $label }}</div>
        </div>
    @endforeach
</div>
