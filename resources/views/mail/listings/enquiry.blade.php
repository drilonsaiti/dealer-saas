<x-mail-frame>
    <p>{{ __('New enquiry from your website') }}@if ($vehicle) – <strong>{{ $vehicle }}</strong>@endif</p>
    <p><strong>{{ $name }}</strong><br>{{ $email }}@if ($email && $phone) · @endif{{ $phone }}</p>
    <p style="white-space:pre-line;border-left:3px solid #d4d4d8;padding-left:12px">{{ $text }}</p>
    <p><a href="{{ $url }}">{{ __('Open the enquiry') }}</a></p>
</x-mail-frame>
