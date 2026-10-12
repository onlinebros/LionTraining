{{-- The Request Assistant conversation, shared by the requester's and the
     reviewer's pages. Assistant and reviewer text is Markdown, rendered with
     raw HTML escaped and unsafe links dropped. --}}
@once
@push('styles')
<style>
    .ra-msg { display:flex; gap:.75rem; margin-bottom:1.25rem; }
    .ra-bubble { padding:.75rem 1rem; border-radius:.5rem; border:1px solid var(--q3-border); background:var(--q3-surface-2); overflow-wrap:anywhere; }
    .ra-msg.assistant .ra-bubble { background:rgba(99,132,255,.08); border-color:rgba(99,132,255,.28); }
    .ra-msg.reviewer .ra-bubble  { background:var(--q3-success-tint); border-color:rgba(62,158,110,.28); }
    .ra-msg.error .ra-bubble     { background:rgba(220,80,80,.08); border-color:rgba(220,80,80,.3); }
    .ra-md > :last-child { margin-bottom:0; }
    .ra-md pre { white-space:pre-wrap; background:rgba(0,0,0,.25); padding:.5rem .75rem; border-radius:.35rem; }
    .ra-md h1,.ra-md h2,.ra-md h3 { font-size:1rem; font-weight:700; margin-top:1rem; }
    .ra-md table { width:100%; margin-bottom:1rem; } .ra-md td,.ra-md th { border:1px solid var(--q3-border); padding:.25rem .5rem; }
</style>
@endpush
@endonce

@foreach($changeRequest->messages as $message)
    @php
        $isAssistant = $message->role === \App\Models\ChangeRequestMessage::ROLE_ASSISTANT;
        $initial = $isAssistant ? 'AI' : strtoupper(mb_substr($message->authorLabel(), 0, 1));
    @endphp
    <div class="ra-msg {{ $message->role }}">
        <div class="q3-avatar">{{ $message->role === 'error' ? '!' : $initial }}</div>
        <div class="flex-grow-1" style="min-width:0;">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="fw-semibold small">{{ $message->authorLabel() }}</span>
                <span class="f-light" style="font-size:.75rem;">{{ $message->created_at->diffForHumans() }}</span>
                @if(!empty($showMeta) && $isAssistant && !empty($message->meta['cost_usd']))
                    <span class="f-light" style="font-size:.7rem;">
                        <span title="What this reply would cost at API prices. It runs on the Claude subscription, so it is not billed.">≈${{ number_format($message->meta['cost_usd'], 3) }}</span> · {{ $message->meta['turns'] ?? '?' }} steps
                        @if(!empty($message->meta['denied_tool_calls'])) · <span class="text-warning">{{ $message->meta['denied_tool_calls'] }} blocked reads</span>@endif
                    </span>
                @endif
                @if(!empty($showMeta) && $message->role === 'error' && !empty($message->meta['error']))
                    <span class="f-light" style="font-size:.7rem;" title="{{ $message->meta['error'] }}">{{ \Illuminate\Support\Str::limit($message->meta['error'], 90) }}</span>
                @endif
            </div>
            <div class="ra-bubble">
                @if($message->role === \App\Models\ChangeRequestMessage::ROLE_USER)
                    {!! nl2br(e($message->body)) !!}
                @else
                    <div class="ra-md">{!! \Illuminate\Support\Str::markdown($message->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                @endif
            </div>
        </div>
    </div>
@endforeach

@if($changeRequest->isAssistantBusy())
    <div class="ra-msg assistant" id="ra-thinking">
        <div class="q3-avatar">AI</div>
        <div class="flex-grow-1">
            <div class="ra-bubble f-light">
                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                The assistant is looking through the project and writing a reply. This usually takes under a minute, sometimes a few.
            </div>
        </div>
    </div>
@endif
