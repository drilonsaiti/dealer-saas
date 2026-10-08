@php
    $brand = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tenant?->brand_color) ? $tenant->brand_color : '#18181b';
    $dealer = $tenant?->legal_name ?: $tenant?->name;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $request->document->title }} – {{ $dealer }}</title>
<style>
    :root { --brand: {{ $brand }}; --ink: #18181b; --muted: #52525b; --line: #e4e4e7; --bg: #f4f4f5; --ok: #15803d; --err: #b91c1c; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 -apple-system, "Helvetica Neue", Arial, sans-serif; }
    header { background: #fff; border-bottom: 3px solid var(--brand); padding: 14px 16px; }
    header .wrap { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .wrap { max-width: 920px; margin: 0 auto; }
    main { padding: 20px 16px 48px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .muted { color: var(--muted); }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-top: 16px; }
    .doc { width: 100%; height: 62vh; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
    .steps { display: flex; gap: 8px; font-size: 14px; margin-top: 10px; flex-wrap: wrap; }
    .steps span { padding: 3px 10px; border-radius: 999px; background: var(--bg); color: var(--muted); }
    .steps span.on { background: var(--brand); color: #fff; }
    .steps span.done { background: #dcfce7; color: var(--ok); }
    label { display: block; font-weight: 600; margin: 14px 0 6px; }
    input[type=text] { width: 100%; padding: 10px 12px; font-size: 16px; border: 1px solid #d4d4d8; border-radius: 8px; }
    input.code { max-width: 200px; letter-spacing: 6px; font-size: 22px; text-align: center; }
    .check { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
    .check input { width: 22px; height: 22px; margin-top: 2px; }
    button { background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 12px 20px; font-size: 16px; font-weight: 600; cursor: pointer; }
    button.link { background: none; color: var(--ink); text-decoration: underline; padding: 0; font-weight: 400; }
    .pad-box { position: relative; border: 1px dashed #a1a1aa; border-radius: 8px; background: #fafafa; touch-action: none; }
    .pad-box canvas { display: block; width: 100%; height: 200px; }
    .pad-box .hint { position: absolute; left: 14px; bottom: 10px; color: #a1a1aa; font-size: 14px; pointer-events: none; }
    .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
    .notice { padding: 12px 14px; border-radius: 8px; margin-top: 16px; }
    .notice.ok { background: #dcfce7; color: var(--ok); }
    .notice.err { background: #fee2e2; color: var(--err); }
    .notice.info { background: #e0f2fe; color: #075985; }
    a { color: var(--ink); }
</style>
</head>
<body>
<header>
    <div class="wrap">
        <strong>{{ $dealer }}</strong>
        <span class="muted" style="font-size: 14px">{{ __('Secure signing') }}</span>
    </div>
</header>
<main>
    <div class="wrap">
        <h1>{{ $request->document->title }}</h1>
        <div class="muted">{{ __('For :name', ['name' => $signer->name]) }}</div>

        @if (session('status'))
            <div class="notice ok">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice err">{{ $errors->first() }}</div>
        @endif

        @if ($signer->hasSigned())
            <div class="card">
                <h2 style="margin-top: 0">{{ __('Thank you, you have signed.') }}</h2>
                <p class="muted">{{ $request->status->value === 'completed'
                    ? __('Everyone has signed. The signed document was sent to you by email.')
                    : __(':dealer signs next. You will receive the signed document by email.', ['dealer' => $dealer]) }}</p>
            </div>
        @elseif (! $request->isOpen())
            <div class="card">
                <h2 style="margin-top: 0">{{ __('This link is no longer valid.') }}</h2>
                <p class="muted">{{ __('Please contact :dealer.', ['dealer' => $dealer]) }} {{ $tenant?->phone }} {{ $tenant?->email }}</p>
            </div>
        @elseif (! $isTurn)
            <div class="card"><p>{{ __('This document cannot be signed right now. Please contact :dealer.', ['dealer' => $dealer]) }}</p></div>
        @else
            <div class="steps">
                <span class="done">1 · {{ __('Read') }}</span>
                <span class="{{ $codeVerified ? 'done' : 'on' }}">2 · {{ __('Confirm with code') }}</span>
                <span class="{{ $codeVerified ? 'on' : '' }}">3 · {{ __('Sign') }}</span>
            </div>

            <div class="card">
                <p style="margin-top: 0">{{ __('Please read the whole document. Scroll inside the frame.') }}
                    <a href="{{ $pdfUrl }}" target="_blank" rel="noopener">{{ __('Open as PDF') }}</a></p>
                <iframe class="doc" title="{{ $request->document->title }}" srcdoc="{{ $documentHtml }}"></iframe>
            </div>

            @if (! $codeVerified)
                <div class="card">
                    <p style="margin-top: 0">{{ config('dealer.signatures.code_channel') === 'sms' && $signer->phone
                        ? __('To confirm it is you, we send a one-time code by SMS to :to.', ['to' => $signer->maskedPhone()])
                        : __('To confirm it is you, we send a one-time code by email to :to.', ['to' => $signer->email]) }}</p>
                    @if ($signer->code_sent_at)
                        <form method="post" action="{{ route('signing.verify', $token) }}">
                            @csrf
                            <label for="code">{{ __('Code') }}</label>
                            <input id="code" class="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
                            <div class="row"><button type="submit">{{ __('Confirm') }}</button></div>
                        </form>
                        <form method="post" action="{{ route('signing.code', $token) }}" style="margin-top: 12px">
                            @csrf
                            <button type="submit" class="link">{{ __('Send a new code') }}</button>
                        </form>
                    @else
                        <form method="post" action="{{ route('signing.code', $token) }}">
                            @csrf
                            <button type="submit">{{ __('Send code') }}</button>
                        </form>
                    @endif
                </div>
            @else
                <form class="card" method="post" action="{{ route('signing.sign', $token) }}" id="sign-form">
                    @csrf
                    <label class="check"><input type="checkbox" name="accepted" value="1" required @checked(old('accepted'))> <span>{{ __('I have read the whole document and accept it.') }}</span></label>
                    <label for="place">{{ __('Place') }}</label>
                    <input id="place" type="text" name="place" value="{{ old('place', $signer->party?->city) }}" required maxlength="100">
                    <label>{{ __('Your signature') }}</label>
                    <div class="pad-box"><canvas id="pad"></canvas><span class="hint" id="pad-hint">{{ __('Sign here with your finger or mouse') }}</span></div>
                    <input type="hidden" name="signature" id="signature">
                    <div class="row">
                        <button type="button" class="link" id="pad-clear">{{ __('Clear') }}</button>
                        <button type="submit">{{ __('Sign now') }}</button>
                    </div>
                </form>
                @include('signing._pad-script', ['canvas' => 'pad', 'input' => 'signature', 'form' => 'sign-form', 'clear' => 'pad-clear', 'hint' => 'pad-hint'])
            @endif
        @endif
    </div>
</main>
</body>
</html>
