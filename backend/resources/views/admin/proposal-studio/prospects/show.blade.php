@extends('layouts.admin')

@section('title', $prospect->company_name)
@section('page-title', $prospect->company_name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.index') }}">Proposal Studio</a></li>
    @if($prospect->account)
        <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.show', $prospect->account) }}">{{ $prospect->account->name }}</a></li>
        <li class="breadcrumb-item active">{{ $prospect->offering->vendor_name }} opportunity</li>
    @else
        <li class="breadcrumb-item active">{{ $prospect->company_name }}</li>
    @endif
@endsection

@php
    $busy = $job !== null;
    $jobLabel = match ($job?->kind) {
        'prospect_draft' => 'drafting the plan and emails',
        'proposal_draft' => 'drafting the proposal page',
        default => 'writing a reply',
    };
    $profile = $prospect->profile ?? [];
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

@push('styles')
<style>
    .ps-msg { display:flex; gap:.75rem; margin-bottom:1.25rem; }
    .ps-bubble { padding:.75rem 1rem; border-radius:.5rem; border:1px solid var(--q3-border); background:var(--q3-surface-2); overflow-wrap:anywhere; }
    .ps-msg.assistant .ps-bubble { background:rgba(99,132,255,.08); border-color:rgba(99,132,255,.28); }
    .ps-msg.error .ps-bubble { background:rgba(220,80,80,.08); border-color:rgba(220,80,80,.3); }
    .ps-msg.prospect .ps-bubble { background:var(--q3-gold-tint); border-color:var(--q3-border-gold); }
    .ps-md > :last-child { margin-bottom:0; }
    .ps-md h1,.ps-md h2,.ps-md h3 { font-size:1rem; font-weight:700; margin-top:1rem; }
    .ps-md table { width:100%; margin-bottom:1rem; } .ps-md td,.ps-md th { border:1px solid var(--q3-border); padding:.25rem .5rem; }
    .ps-facts td { padding:.3rem .5rem; vertical-align:top; }
    .ps-basis { font-size:.7rem; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

<div class="row g-3">

    {{-- What we know, and the drafts --}}
    <div class="col-xl-5 order-xl-2">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">What we know</h6>
                <span class="badge {{ $prospect->statusBadgeClass() }}">{{ $prospect->statusLabel() }}</span>
            </div>
            <div class="card-body">
                @if(empty($profile))
                    <p class="f-light small mb-0">The assistant fills this in as you talk: a summary, the facts so far, each site, and what is still open.</p>
                @else
                    @if(!empty($profile['summary']))<p>{{ $profile['summary'] }}</p>@endif

                    @if(!empty($profile['facts']))
                        <table class="ps-facts small w-100 mb-3">
                            @foreach($profile['facts'] as $fact)
                                <tr>
                                    <td class="f-light text-nowrap">{{ $fact['label'] }}</td>
                                    <td>{{ is_scalar($fact['value']) ? $fact['value'] : json_encode($fact['value']) }}
                                        @if(($fact['basis'] ?? '') === 'assumed')<span class="badge badge-light-warning ps-basis">assumed</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    @if(!empty($profile['sites']))
                        <h6 class="fw-bold small text-uppercase f-light mt-3">Sites</h6>
                        @foreach($profile['sites'] as $site)
                            <div class="small mb-2">
                                <strong>{{ $site['name'] ?? '' ?: 'Site '.$loop->iteration }}</strong>
                                @foreach(['location' => 'Location', 'sq_ft' => 'Sq ft', 'hvac' => 'HVAC', 'occupancy' => 'Occupancy', 'notes' => 'Notes'] as $key => $label)
                                    @if(filled($site[$key] ?? null) && is_scalar($site[$key]))<br><span class="f-light">{{ $label }}:</span> {{ $site[$key] }}@endif
                                @endforeach
                            </div>
                        @endforeach
                    @endif

                    @if(!empty($profile['open_questions']))
                        <h6 class="fw-bold small text-uppercase f-light mt-3">Still to ask the prospect</h6>
                        <ul class="small mb-0">@foreach($profile['open_questions'] as $q)<li>{{ $q }}</li>@endforeach</ul>
                    @endif
                    @if(!empty($profile['vendor_questions']))
                        <h6 class="fw-bold small text-uppercase f-light mt-3">For {{ $prospect->offering->vendor_name }}</h6>
                        <ul class="small mb-0">@foreach($profile['vendor_questions'] as $q)<li>{{ $q }}</li>@endforeach</ul>
                    @endif
                @endif
            </div>
        </div>

        @include('admin.proposal-studio.prospects._proposal')
        @include('admin.proposal-studio.prospects._portal')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Plan and email drafts</h6>
                <form method="POST" action="{{ route('admin.proposal-studio.prospects.draft', $prospect) }}">
                    @csrf
                    <button class="btn btn-outline-primary btn-sm" @disabled($busy)>{{ $prospect->draft ? 'Redraft' : 'Draft plan and emails' }}</button>
                </form>
            </div>
            <div class="card-body">
                @if($prospect->draft)
                    <p class="f-light small">Drafted {{ $prospect->draft_at?->diffForHumans() }}. Read it through before anything is sent: claims must match the documents word for word.</p>
                    <div class="ps-md">{!! $md($prospect->draft) !!}</div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="ps-copy-draft">Copy as Markdown</button>
                    <textarea id="ps-draft-raw" class="visually-hidden" readonly aria-hidden="true">{{ $prospect->draft }}</textarea>
                @else
                    <p class="f-light small mb-0">When the picture is clear enough, ask for a draft: a recommended plan, what still needs confirming, and an introduction, proposal and follow-up email.</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <a class="fw-bold small" data-bs-toggle="collapse" href="#ps-record" role="button" aria-expanded="false" aria-controls="ps-record">Edit company details</a>
            </div>
            <div class="collapse @if($errors->any() && ! $errors->has('body')) show @endif" id="ps-record">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.proposal-studio.prospects.update', $prospect) }}">
                        @csrf @method('PUT')
                        @include('admin.proposal-studio.prospects._fields', ['prospect' => $prospect])
                        <div class="mt-3">
                            <label class="form-label small fw-semibold" for="ps-status">Status</label>
                            <select id="ps-status" name="status" class="form-select">
                                @foreach(\App\Models\Prospect::STATUSES as $key => $label)
                                    <option value="{{ $key }}" @selected($prospect->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mt-3">
                            <label class="form-label small fw-semibold" for="ps-outcome">Why it was won or lost</label>
                            <textarea id="ps-outcome" name="outcome_note" rows="2" class="form-control" maxlength="5000" placeholder="What decided it. The assistant reads this when it works on other companies for this offering, without naming this one.">{{ old('outcome_note', $prospect->outcome_note) }}</textarea>
                        </div>
                        <button class="btn btn-primary btn-sm mt-3">Save</button>
                    </form>
                    <p class="f-light small mt-3 mb-0">Started by {{ $prospect->creator?->name ?? 'someone' }}, {{ $prospect->created_at->format('M j, Y') }}. Offering: <a href="{{ route('admin.proposal-studio.offerings.show', $prospect->offering) }}">{{ $prospect->offering->name }}</a>.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Conversation --}}
    <div class="col-xl-7 order-xl-1">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Discovery</h6></div>
            <div class="card-body">
                @foreach($prospect->messages as $message)
                    @php $isAssistant = $message->role === \App\Models\ProspectMessage::ROLE_ASSISTANT; @endphp
                    <div class="ps-msg {{ $message->role }}">
                        <div class="q3-avatar">{{ $message->role === 'error' ? '!' : ($isAssistant ? 'AI' : strtoupper(mb_substr($message->authorLabel(), 0, 1))) }}</div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-semibold small">{{ $message->authorLabel() }}</span>
                                <span class="f-light" style="font-size:.75rem;">{{ $message->created_at->diffForHumans() }}</span>
                                @if($message->role === 'error' && !empty($message->meta['error']))
                                    <span class="f-light" style="font-size:.7rem;" title="{{ $message->meta['error'] }}">{{ \Illuminate\Support\Str::limit($message->meta['error'], 90) }}</span>
                                @endif
                            </div>
                            <div class="ps-bubble">
                                @if($message->role === \App\Models\ProspectMessage::ROLE_USER)
                                    {!! nl2br(e($message->body)) !!}
                                @else
                                    <div class="ps-md">{!! $md($message->body) !!}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($busy)
                    <div class="ps-msg assistant">
                        <div class="q3-avatar">AI</div>
                        <div class="flex-grow-1">
                            <div class="ps-bubble f-light">
                                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                The assistant is {{ $jobLabel }}. This usually takes under a minute@if($job->status === 'pending' && $job->created_at->lt(now()->subMinutes(2))), but it has not been picked up yet: the worker may be down@endif.
                            </div>
                        </div>
                    </div>
                @endif

                <hr>
                <form method="POST" action="{{ route('admin.proposal-studio.prospects.message', $prospect) }}">
                    @csrf
                    <div class="mb-2">
                        <textarea class="form-control @error('body') is-invalid @enderror" name="body" rows="3" maxlength="20000" required
                                  placeholder="{{ $busy ? 'Waiting for the assistant…' : 'Answer its questions, add notes from a call, or ask what it thinks…' }}" @disabled($busy)>{{ old('body') }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" @disabled($busy)>Send</button>
                </form>
            </div>
        </div>
    </div>

</div>
</div>

@push('scripts')
<script>
    (function () {
        var copy = document.getElementById('ps-copy-draft');
        if (copy) {
            copy.addEventListener('click', function () {
                var raw = document.getElementById('ps-draft-raw').value;
                var done = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy as Markdown'; }, 2000); };
                if (navigator.clipboard) { navigator.clipboard.writeText(raw).then(done); }
            });
        }
    })();
</script>
@if($busy)
<script>
    // Reload once the assistant's reply has landed.
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.proposal-studio.prospects.status', $prospect)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (s) { if (s.busy) { poll(); } else { window.location.reload(); } })
                .catch(poll);
        }, 4000);
    })();
</script>
@endif
@endpush
@endsection
