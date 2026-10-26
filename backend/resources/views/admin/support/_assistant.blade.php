{{-- The Q3-AI Assistant on a support ticket (memory-bank/request-assistant.md).
     Its answers are Markdown, rendered with raw HTML escaped and unsafe links
     dropped; the draft reply is plain text and goes into the reply box. --}}
@php
    $assist = $ticket->assist;
    $busy = (bool) $assist?->isBusy();
    $stuck = (bool) $assist?->isStuck();
    $failed = $assist?->messages->last()?->role === \App\Models\SupportAssistMessage::ROLE_ERROR;
    $answer = $assist?->latestAnswer();
    $verdict = $answer?->verdict();
    $draft = $answer?->section('draft');
    $earlier = $assist ? $assist->messages->reject(fn ($m) => $answer && $m->id === $answer->id) : collect();
    $markdown = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

@once
@push('styles')
<style>
    .q3ai { border:1px solid rgba(99,132,255,.28); background:rgba(99,132,255,.05); border-radius:.5rem; padding:1rem; }
    .q3ai-md > :last-child { margin-bottom:0; }
    .q3ai-md h1,.q3ai-md h2,.q3ai-md h3 { font-size:.95rem; font-weight:700; margin-top:.75rem; }
    .q3ai-draft { white-space:pre-wrap; overflow-wrap:anywhere; background:var(--q3-surface-2); border:1px solid var(--q3-border); border-radius:.35rem; padding:.75rem; font-size:.9rem; }
    .q3ai-log { font-size:.85rem; }
    .q3ai-log .q3ai-entry { border-left:3px solid var(--q3-border); padding:.25rem .75rem; margin-bottom:.75rem; overflow-wrap:anywhere; }
    .q3ai-log .q3ai-entry.assistant { border-color:rgba(99,132,255,.5); }
    .q3ai-log .q3ai-entry.error { border-color:rgba(220,80,80,.6); }
</style>
@endpush
@endonce

<div class="q3ai mb-4" id="q3-ai">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
        <h6 class="mb-0 fw-bold">
            <i data-feather="cpu" style="width:15px;height:15px;"></i> Q3-AI Assistant
        </h6>
        @if($verdict)
            <span class="badge {{ \App\Models\SupportAssistMessage::VERDICTS[$verdict][1] }}">{{ \App\Models\SupportAssistMessage::VERDICTS[$verdict][0] }}</span>
        @endif
    </div>

    @if(! $assist)
        <p class="small f-light mb-3">
            Q3-AI reads this ticket alongside the application's code and the team's notes. It drafts a reply for you to
            check and send, and tells you whether the request needs something the system doesn't do yet.
            The member's name, email and phone numbers are removed before the ticket is sent to it.
        </p>
    @endif

    @if($answer)
        @if($verdict && !empty($answer->meta['verdict_reason']))
            <p class="small mb-2"><strong>Why:</strong> {{ $answer->meta['verdict_reason'] }}</p>
        @endif

        @if($answer->section('today') || $draft)
            @if($answer->section('today'))
                <div class="small fw-semibold mb-1">What the system does today</div>
                <div class="q3ai-md small mb-3">{!! $markdown($answer->section('today')) !!}</div>
            @endif

            @if($draft)
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="small fw-semibold">Draft reply</span>
                    <span class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-q3ai-copy>Copy</button>
                        <button type="button" class="btn btn-sm btn-primary" data-q3ai-use>Use this draft</button>
                    </span>
                </div>
                <div class="q3ai-draft mb-1" id="q3ai-draft"
                     data-name="{{ \App\Services\SupportAssistant\Redactor::firstName($ticket) }}">{{ $draft }}</div>
                <p class="f-light mb-3" style="font-size:.72rem;">{name} is filled in with the member's first name when you use or copy it. Check it before sending.</p>
            @endif

            @if($verdict === 'not_built')
                <div class="p-2 rounded mb-3" style="background:rgba(220,80,80,.06);border:1px solid rgba(220,80,80,.25);">
                    <div class="small fw-semibold mb-1">Change needed</div>
                    <div class="q3ai-md small">{!! $markdown($answer->section('change') ?? 'Q3-AI did not describe the change.') !!}</div>
                    <div class="mt-2">
                        @if($assist->changeRequest)
                            <a href="{{ route('admin.change-requests.show', $assist->changeRequest) }}" class="btn btn-sm btn-outline-primary">
                                Open {{ $assist->changeRequest->number }} ({{ $assist->changeRequest->statusLabel() }})
                            </a>
                        @elseif(auth()->user()->isSuperAdmin())
                            <form method="POST" action="{{ route('admin.support.assist.change-request', $ticket) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary" @disabled($busy)>Start a change request</button>
                            </form>
                            <span class="f-light ms-1" style="font-size:.72rem;">The Request Assistant works it into a plan for review.</span>
                        @else
                            <span class="f-light" style="font-size:.75rem;">Let a super admin know: they can turn this into a change request.</span>
                        @endif
                    </div>
                </div>
            @endif
        @else
            {{-- Not written to the headings: show it as it came. --}}
            <div class="q3ai-md small mb-3">{!! $markdown($answer->body) !!}</div>
        @endif

        <p class="f-light mb-2" style="font-size:.72rem;">
            {{ $answer->created_at->diffForHumans() }}. Q3-AI reads the code, not this member's account or payments. Check what it says before acting on it.
        </p>
    @endif

    @if($earlier->isNotEmpty())
        <details class="mb-3">
            <summary class="small f-light">Conversation with Q3-AI ({{ $earlier->count() }})</summary>
            <div class="q3ai-log mt-2">
                @foreach($earlier as $message)
                    <div class="q3ai-entry {{ $message->role }}">
                        <div class="f-light" style="font-size:.72rem;">
                            @if($message->role === \App\Models\SupportAssistMessage::ROLE_ASSISTANT) Q3-AI
                            @elseif($message->role === \App\Models\SupportAssistMessage::ROLE_ERROR) System
                            @else {{ $message->user?->name ?? 'Staff' }}
                            @endif
                            · {{ $message->created_at->diffForHumans() }}
                        </div>
                        @if($message->role === \App\Models\SupportAssistMessage::ROLE_USER)
                            {!! nl2br(e($message->body)) !!}
                        @else
                            <div class="q3ai-md">{!! $markdown($message->body) !!}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if($busy)
        <div class="small f-light mb-0">
            <span class="spinner-border spinner-border-sm me-2" role="status"></span>
            Q3-AI is reading the ticket and the code. This usually takes under a minute, sometimes a few.
        </div>
    @else
        @if($failed)
            <div class="alert alert-danger small py-2">{{ $assist->messages->last()->body }}</div>
        @elseif($stuck)
            <div class="alert alert-warning small py-2">Q3-AI hasn't picked up the last question. The assistant on the dev server may be down. Try again, or let the team know.</div>
        @endif
        <form method="POST" action="{{ route('admin.support.assist', $ticket) }}">
            @csrf
            @if($assist)
                <textarea name="note" class="form-control form-control-sm mb-2" rows="2" maxlength="5000"
                          placeholder="Ask Q3-AI to change the draft or look into something (e.g. &quot;shorter&quot;, &quot;they already reset their password&quot;)">{{ old('note') }}</textarea>
                <button type="submit" class="btn btn-sm btn-outline-primary">Ask Q3-AI</button>
            @else
                <textarea name="note" class="form-control form-control-sm mb-2" rows="2" maxlength="5000"
                          placeholder="Optional: anything Q3-AI should know (what you've already checked, what you think is wrong)">{{ old('note') }}</textarea>
                <button type="submit" class="btn btn-sm btn-primary">Ask Q3-AI for a draft</button>
            @endif
        </form>
    @endif
</div>

@push('scripts')
<script>
(function () {
    var draft = document.getElementById('q3ai-draft');
    if (draft) {
        var text = function () { return draft.textContent.replace(/\{name\}/g, draft.dataset.name); };
        document.querySelector('[data-q3ai-use]').addEventListener('click', function () {
            var box = document.getElementById('support-reply-body');
            box.value = text();
            box.focus();
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        document.querySelector('[data-q3ai-copy]').addEventListener('click', function (e) {
            navigator.clipboard.writeText(text()).then(function () { e.target.textContent = 'Copied'; });
        });
    }
    @if($busy)
    // Reload once Q3-AI's answer has landed.
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.support.assist.status', $ticket)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (s) { if (s.busy) { poll(); } else { window.location.hash = 'q3-ai'; window.location.reload(); } })
                .catch(poll);
        }, 4000);
    })();
    @endif
})();
</script>
@endpush
