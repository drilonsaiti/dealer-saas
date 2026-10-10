{{-- A WhatsApp conversation: customer on the left, dealer on the right. Text is escaped. --}}
@php
    $messages = $getState();
    $statusIcon = ['sending' => '…', 'sent' => '✓', 'delivered' => '✓✓', 'read' => '✓✓', 'failed' => '⚠'];
@endphp
<div style="display:flex;flex-direction:column;gap:.5rem;max-width:48rem">
    @foreach ($messages as $message)
        @php $out = $message->direction === 'out'; @endphp
        <div style="display:flex;justify-content:{{ $out ? 'flex-end' : 'flex-start' }}">
            <div style="max-width:80%;padding:.5rem .75rem;border-radius:.75rem;white-space:pre-line;line-height:1.4;{{ $out ? 'background:rgba(34,197,94,.14)' : 'background:rgba(148,163,184,.16)' }}">
                @if ($message->document)
                    <div style="font-size:.85em;opacity:.8">📎 {{ $message->document->title }}</div>
                @endif
                {{ $message->body }}
                <div style="font-size:.72em;opacity:.65;text-align:right;margin-top:.15rem">
                    {{ $message->created_at->format('d.m.Y H:i') }}
                    @if ($out) <span title="{{ $message->status }}" style="{{ $message->status === 'read' ? 'color:#2563eb' : '' }}">{{ $statusIcon[$message->status] ?? '' }}</span> @endif
                </div>
                @if ($message->error)
                    <div style="font-size:.75em;color:#dc2626">{{ $message->error }}</div>
                @endif
            </div>
        </div>
    @endforeach
</div>
