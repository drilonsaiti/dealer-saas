<x-mail-frame>
    <p>{{ __('Hello :name', ['name' => $recipientName]) }}</p>
    <p>{{ __('Attached is :document from :dealer.', ['document' => $title, 'dealer' => $dealerName]) }}</p>
    @if ($amount)
        <p>{{ __('Please pay :amount by :date using the QR bill in the PDF.', ['amount' => $amount, 'date' => $dueOn]) }}</p>
    @endif
    <p style="color:#52525b;font-size:13px">{{ $dealerName }}</p>
</x-mail-frame>
