<x-mail-frame>
    <p>{{ __('Hello :name', ['name' => $signerName]) }}</p>
    <p>{{ __('":document" is now signed by everyone. Your copy is attached.', ['document' => $documentTitle]) }}</p>
    <p style="color:#52525b;font-size:13px">{{ __('The PDF is sealed: any later change to it can be detected.') }} {{ $dealerName }}</p>
</x-mail-frame>
