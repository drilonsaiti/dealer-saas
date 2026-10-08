<x-mail-frame>
    <p>{{ __('Hello :name', ['name' => $signerName]) }}</p>
    <p>{{ __(':dealer asks you to read and sign ":document".', ['dealer' => $dealerName, 'document' => $documentTitle]) }}</p>
    <p style="margin:28px 0">
        <a href="{{ $url }}" style="background:#18181b;color:#fff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block">{{ __('Read and sign') }}</a>
    </p>
    <p style="color:#52525b;font-size:13px">
        {{ __('You will get a one-time code to confirm it is you.') }}
        @if ($expiresAt)
            {{ __('The link is valid until :date.', ['date' => $expiresAt->format('d.m.Y')]) }}
        @endif
    </p>
</x-mail-frame>
