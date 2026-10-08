<x-mail-frame>
    <p>{{ __('Your code to sign the document of :dealer:', ['dealer' => $dealerName]) }}</p>
    <p style="font-size:28px;letter-spacing:6px;font-weight:bold;margin:20px 0">{{ $code }}</p>
    <p style="color:#52525b;font-size:13px">{{ __('The code is valid for 10 minutes. If you did not ask for it, ignore this email.') }}</p>
</x-mail-frame>
