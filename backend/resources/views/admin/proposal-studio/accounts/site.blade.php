@extends('layouts.admin')

@section('title', 'Site builder · '.$account->name)
@section('page-title', $account->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.show', $account) }}">{{ $account->name }}</a></li>
    <li class="breadcrumb-item active">Site builder</li>
@endsection

@php
    use App\Models\MicrositeSection as MS;
    $requests = $account->members->filter->isPendingRequest();
    $members = $account->members->reject->isPendingRequest()->filter(fn ($m) => $m->status === 'invited');
    $published = $sections->filter->isPublished()->count();
    $offering = $account->offering;
    $previewUrl = route('admin.proposal-studio.accounts.site.preview', [$account, 'embed' => 1]);
    $clientUrl = $account->site_ref ? route('microsite.show', $account->site_ref) : null;
    $layoutIcons = ['hero' => '▣', 'cards' => '▦', 'timeline' => '⋮', 'proof' => '❝', 'faq' => '?', 'plan' => '☑', 'cta' => '➜', 'text' => '¶'];
    $editing = $selected?->id;
    $readingBySection = $reading->groupBy('microsite_section_id');
    $memberNames = $account->members->mapWithKeys(fn ($m) => [$m->id => $m->label()]);
@endphp

@push('styles')
<style>
    .msb { --msb-accent: {{ $account->accent() }}; display: grid; grid-template-columns: minmax(360px, 440px) 1fr; gap: 18px; align-items: start; }
    @media (max-width: 1199px) { .msb { grid-template-columns: 1fr; } .msb-stage { position: static !important; height: 75vh !important; } }
    .msb-panel { background: var(--q3-surface, #fff); border: 1px solid var(--q3-border); border-radius: 14px; overflow: hidden; }
    .msb-tabs { display: flex; border-bottom: 1px solid var(--q3-border); }
    .msb-tabs a { flex: 1; text-align: center; padding: 12px 6px; font-weight: 600; font-size: 13px; color: var(--q3-text-muted, #6b7280); text-decoration: none; border-bottom: 2px solid transparent; }
    .msb-tabs a.active { color: inherit; border-bottom-color: var(--msb-accent); }
    .msb-tabs .badge { font-size: 10px; }
    .msb-body { padding: 16px; }
    .msb-magic { position: relative; border-radius: 14px; padding: 18px; color: #fff; overflow: hidden; background: radial-gradient(120% 120% at 0% 0%, color-mix(in srgb, var(--msb-accent) 70%, #111), #0b0d12 70%); }
    .msb-magic::after { content: ""; position: absolute; width: 240px; height: 240px; right: -80px; top: -90px; border-radius: 50%; background: radial-gradient(circle, rgba(212,175,55,.45), transparent 65%); pointer-events: none; }
    .msb-magic h6 { color: #fff; font-weight: 800; letter-spacing: -.01em; }
    .msb-magic textarea { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.18); color: #fff; }
    .msb-magic textarea::placeholder { color: rgba(255,255,255,.55); }
    .msb-magic .form-check-label, .msb-magic .small { color: rgba(255,255,255,.75); }
    .msb-magic .btn-magic { background: linear-gradient(100deg, var(--msb-accent), #d4af37); color: #111; font-weight: 800; border: 0; }
    .msb-kb { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12px; position: relative; z-index: 1; }
    .msb-kb span { background: rgba(255,255,255,.1); border-radius: 999px; padding: 3px 10px; }
    .msb-kb .warn { background: rgba(255,196,0,.22); }
    .msb-outline { list-style: none; padding: 0; margin: 0; display: grid; gap: 6px; }
    .msb-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--q3-border); border-radius: 10px; cursor: pointer; background: var(--q3-surface, #fff); transition: border-color .15s, box-shadow .15s; }
    .msb-item:hover { border-color: color-mix(in srgb, var(--msb-accent) 50%, var(--q3-border)); }
    .msb-item.active { border-color: var(--msb-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--msb-accent) 18%, transparent); }
    .msb-item.dragging { opacity: .4; }
    .msb-item.over { border-top: 3px solid var(--msb-accent); }
    .msb-grip { cursor: grab; color: #9ca3af; font-size: 14px; user-select: none; }
    .msb-lay { width: 30px; height: 30px; flex: none; border-radius: 8px; display: grid; place-items: center; font-size: 14px; background: color-mix(in srgb, var(--msb-accent) 14%, transparent); }
    .msb-item .t { flex: 1; min-width: 0; }
    .msb-item .t b { display: block; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .msb-item .t small { color: #6b7280; }
    .msb-dot { width: 9px; height: 9px; border-radius: 50%; background: #cbd5e1; flex: none; }
    .msb-dot.pub { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
    .msb-dot.busy { background: var(--msb-accent); animation: msbPulse 1s infinite alternate; }
    @keyframes msbPulse { to { box-shadow: 0 0 0 6px color-mix(in srgb, var(--msb-accent) 25%, transparent); } }
    .msb-shimmer { background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--msb-accent) 14%, transparent), transparent); background-size: 200% 100%; animation: msbShimmer 1.4s infinite; }
    @keyframes msbShimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }
    .msb-layouts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .msb-layouts label { border: 1px solid var(--q3-border); border-radius: 10px; padding: 8px 4px; text-align: center; font-size: 11px; cursor: pointer; margin: 0; }
    .msb-layouts label span { display: block; font-size: 18px; line-height: 1.2; }
    .msb-layouts input { position: absolute; opacity: 0; pointer-events: none; }
    .msb-layouts input:checked + label { border-color: var(--msb-accent); background: color-mix(in srgb, var(--msb-accent) 12%, transparent); font-weight: 700; }
    .msb-row { border: 1px solid var(--q3-border); border-radius: 10px; padding: 10px; position: relative; background: var(--q3-surface-2, #f8fafc); }
    .msb-row .rm { position: absolute; top: 4px; right: 8px; border: 0; background: none; color: #9ca3af; font-size: 18px; line-height: 1; }
    .msb-row .rm:hover { color: #dc2626; }
    .msb-ask { border: 1px dashed color-mix(in srgb, var(--msb-accent) 60%, var(--q3-border)); border-radius: 12px; padding: 12px; background: color-mix(in srgb, var(--msb-accent) 6%, transparent); }
    .msb-claim { display: block; border: 1px solid var(--q3-border); border-radius: 10px; padding: 8px 10px 8px 34px; position: relative; font-size: 13px; cursor: pointer; margin-bottom: 6px; }
    .msb-claim input { position: absolute; left: 11px; top: 11px; }
    .msb-claim:has(input:checked) { border-color: var(--msb-accent); background: color-mix(in srgb, var(--msb-accent) 8%, transparent); }
    .msb-themes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .msb-themes input { position: absolute; opacity: 0; pointer-events: none; }
    .msb-themes label { margin: 0; cursor: pointer; border: 2px solid transparent; border-radius: 12px; padding: 4px; }
    .msb-themes input:checked + label { border-color: var(--msb-accent); }
    .msb-swatch { height: 64px; border-radius: 9px; position: relative; overflow: hidden; }
    .msb-swatch i { position: absolute; left: 10px; right: 30%; height: 6px; border-radius: 4px; }
    .msb-stage { position: sticky; top: 84px; height: calc(100vh - 110px); display: flex; flex-direction: column; border-radius: 14px; overflow: hidden; border: 1px solid var(--q3-border); background: #0b0d12; }
    .msb-chrome { display: flex; align-items: center; gap: 10px; padding: 9px 12px; background: #14171f; color: #cbd5e1; font-size: 12px; }
    .msb-chrome .dots i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 5px; background: #374151; }
    .msb-chrome .url { flex: 1; min-width: 0; background: #0b0d12; border-radius: 8px; padding: 5px 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #94a3b8; }
    .msb-chrome button, .msb-chrome a { background: none; border: 0; color: #94a3b8; padding: 3px 7px; border-radius: 6px; font-size: 13px; text-decoration: none; }
    .msb-chrome button.on, .msb-chrome button:hover, .msb-chrome a:hover { background: #1f2430; color: #fff; }
    .msb-frame-wrap { flex: 1; display: flex; justify-content: center; overflow: hidden; background: repeating-linear-gradient(45deg, #0d1016, #0d1016 12px, #0f131a 12px, #0f131a 24px); }
    .msb-frame-wrap iframe { border: 0; width: 100%; height: 100%; background: #fff; transition: width .35s ease; }
    .msb-writing { position: absolute; left: 50%; bottom: 18px; transform: translateX(-50%); background: rgba(17,20,28,.92); color: #fff; padding: 9px 16px; border-radius: 999px; font-size: 13px; display: flex; gap: 8px; align-items: center; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
    .msb-bar { height: 8px; border-radius: 6px; background: linear-gradient(90deg, var(--msb-accent), #d4af37); }
</style>
@endpush

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    {{-- Status strip --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="badge {{ $published ? 'badge-light-success' : 'badge-light-secondary' }}">{{ $published }} of {{ $sections->count() }} sections live</span>
        @if($offering)
            <a class="badge badge-light-primary text-decoration-none" href="{{ route('admin.proposal-studio.offerings.show', $offering) }}">{{ $offering->name }} · {{ $library['documents'] }} documents · {{ $library['approved'] }} of {{ $library['claims'] }} claims approved</a>
        @else
            <a class="badge badge-light-warning text-decoration-none" href="{{ route('admin.proposal-studio.accounts.site', [$account, 'tab' => 'design']) }}">Choose the product line this site presents</a>
        @endif
        <span class="badge badge-light-info">{{ $members->where('revoked_at', null)->count() }} invited</span>
        @if($clientUrl)<span class="small f-light ms-auto">Client link: <code>{{ $clientUrl }}</code></span>@endif
    </div>

<div class="msb">
    {{-- ── Left: the panel ─────────────────────────────────────────────── --}}
    <div class="msb-panel">
        <div class="msb-tabs" role="tablist">
            @foreach(['build' => 'Build', 'design' => 'Design', 'access' => 'Access', 'insights' => 'Insights'] as $key => $label)
                <a href="{{ route('admin.proposal-studio.accounts.site', [$account, 'tab' => $key]) }}" class="{{ $tab === $key && ! ($key !== 'build' && $editing) ? 'active' : '' }}" data-tab="{{ $key }}">{{ $label }}@if($key === 'access' && $requests->isNotEmpty()) <span class="badge bg-danger">{{ $requests->count() }}</span>@endif</a>
            @endforeach
        </div>

        <div class="msb-body">
        @if($tab === 'build' || $editing)

            {{-- Outline + the assistant --}}
            <div id="msb-outline" @if($editing) hidden @endif>
                <form method="POST" action="{{ route('admin.proposal-studio.accounts.site.build', $account) }}" class="msb-magic mb-3">
                    @csrf
                    <h6 class="mb-1">✦ {{ $sections->isEmpty() ? 'Build their site with AI' : 'Ask the assistant' }}</h6>
                    <p class="small mb-2" style="position:relative;z-index:1;">{{ $sections->isEmpty()
                        ? 'Say what this site should achieve. The assistant lays out nine sections and writes them from your library and what we know about '.$account->name.'. You review, then publish.'
                        : 'Change the goal and rewrite, or fill in any empty sections.' }}</p>
                    <textarea name="goal" rows="3" class="form-control form-control-sm mb-2" maxlength="5000" style="position:relative;z-index:1;" placeholder="e.g. Win a pilot in two classrooms before the spring term. The business manager cares about cost; the principal about sick days.">{{ old('goal', $account->site_goal) }}</textarea>
                    <div class="msb-kb mb-2">
                        @if($offering)
                            <span>📚 {{ $offering->name }}: {{ $library['documents'] }} documents</span>
                            <span class="{{ $library['approved'] ? '' : 'warn' }}">{{ $library['approved'] }} approved claims</span>
                            @if($library['documents'] === 0)<span class="warn">Library is empty here</span>@endif
                        @else
                            <span class="warn">No product line chosen: Design tab</span>
                        @endif
                        <span>{{ $account->contacts->count() ?? 0 }} people · {{ $account->opportunities->count() }} opportunities</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap" style="position:relative;z-index:1;">
                        <button class="btn btn-magic btn-sm px-3">{{ $sections->isEmpty() ? '✦ Build my site' : '✦ Write empty sections' }}</button>
                        @if($sections->isNotEmpty())
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="rewrite" value="1" id="msb-rewrite"><label class="form-check-label small" for="msb-rewrite">Rewrite sections already written</label></div>
                        @endif
                    </div>
                </form>

                @if($offering && $library['approved'] === 0 && $library['claims'] > 0)
                    <div class="alert alert-warning small py-2">None of {{ $offering->name }}'s {{ $library['claims'] }} claims is approved yet, so the site can't show any results. <a href="{{ route('admin.proposal-studio.offerings.show', [$offering, 'tab' => 'claims']) }}">Review the claims</a>.</div>
                @endif

                @if($sections->isNotEmpty())
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-uppercase f-light" style="letter-spacing:.06em;">Sections · drag to reorder</span>
                    </div>
                    <ul class="msb-outline" id="msb-list">
                        @foreach($sections as $section)
                            @php $isBusy = in_array($section->id, $busy, true); @endphp
                            <li class="msb-item {{ $isBusy ? 'msb-shimmer' : '' }}" draggable="true" data-id="{{ $section->id }}">
                                <span class="msb-grip" title="Drag to reorder">⋮⋮</span>
                                <span class="msb-lay" title="{{ $section->layoutLabel() }}">{{ $layoutIcons[$section->isWritten() ? $section->layout : 'text'] ?? '¶' }}</span>
                                <span class="t"><b>{{ $section->title }}</b><small>{{ $section->kindLabel() }} · {{ $isBusy ? 'assistant writing…' : ($section->hasContent() ? ($section->isPublished() ? 'live' : 'draft') : 'empty') }}@if($section->comments->whereNull('reply')->isNotEmpty()) · <span class="text-danger">{{ $section->comments->whereNull('reply')->count() }} feedback</span>@endif</small></span>
                                <span class="msb-dot {{ $isBusy ? 'busy' : ($section->isPublished() ? 'pub' : '') }}"></span>
                            </li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.store', $account) }}" class="d-flex gap-2 mt-3 flex-wrap">
                        @csrf
                        <select name="kind" class="form-select form-select-sm" style="flex: 1; min-width: 10rem;" aria-label="Kind of section">
                            @foreach(MS::KINDS as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <input type="hidden" name="draft" value="1">
                        <button class="btn btn-outline-primary btn-sm">+ Add and write</button>
                    </form>
                    @if($published < $sections->count())
                        <p class="small f-light mt-3 mb-0">Click a section here or in the preview to edit it. Publish each one when it is right; the client sees only what is published.</p>
                    @endif
                @endif
            </div>

            {{-- One editor per section; the outline or the preview opens it. --}}
            @foreach($sections as $section)
                @php
                    $isBusy = in_array($section->id, $busy, true);
                    $sOffering = $section->offering();
                    $claims = $sOffering ? ($claimsByOffering[$sOffering->id] ?? collect()) : collect();
                    $chosen = array_map('intval', $section->claim_ids ?? []);
                    $c = old('section_id') == $section->id ? (old('content') ?? $section->c()) : $section->c();
                @endphp
                <div class="msb-editor" data-editor="{{ $section->id }}" @if($editing !== $section->id) hidden @endif>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <a href="{{ route('admin.proposal-studio.accounts.site', $account) }}" class="btn btn-light btn-sm text-nowrap" data-back>← Sections</a>
                        <span class="small f-light">{{ $section->kindLabel() }}@if($section->drafted_at) · written {{ $section->drafted_at->diffForHumans() }}@endif</span>
                        <span class="ms-auto badge {{ $section->isPublished() ? 'badge-light-success' : 'badge-light-secondary' }}">{{ $section->isPublished() ? 'Live' : 'Draft' }}</span>
                    </div>

                    @if($isBusy)
                        <div class="alert alert-info small msb-shimmer"><span class="spinner-border spinner-border-sm me-1"></span>The assistant is writing this section. It appears here and in the preview when done.</div>
                    @endif

                    @foreach($section->comments->whereNull('reply') as $comment)
                        <div class="alert alert-warning small">
                            <strong>{{ $comment->member?->label() ?? 'Someone' }}</strong> <span class="f-light">· {{ $comment->created_at->diffForHumans() }}</span>
                            <div style="white-space:pre-line;">{{ $comment->body }}</div>
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.comments.reply', [$account, $comment]) }}" class="mt-2">
                                @csrf
                                <textarea name="reply" rows="2" class="form-control form-control-sm mb-2" required maxlength="10000" placeholder="Your reply. They are emailed."></textarea>
                                <button class="btn btn-primary btn-sm">Reply</button>
                            </form>
                        </div>
                    @endforeach

                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.update', [$account, $section]) }}" data-dirty>
                        @csrf @method('PUT')
                        <input type="hidden" name="section_id" value="{{ $section->id }}">

                        @if($section->isWritten())
                            <div class="msb-ask mb-3">
                                <label class="form-label small fw-bold mb-1" for="ask-{{ $section->id }}">✦ Ask the assistant to {{ $section->hasContent() ? 'change it' : 'write it' }}</label>
                                <textarea id="ask-{{ $section->id }}" name="instruction" rows="2" maxlength="2000" class="form-control form-control-sm mb-2" placeholder="{{ $section->hasContent() ? 'e.g. Shorter. Speak to the facilities manager. Add a step for the site survey.' : 'Optional: anything to stress' }}"></textarea>
                                <button class="btn btn-primary btn-sm" name="then" value="draft" @disabled($isBusy)>✦ {{ $section->hasContent() ? 'Rewrite' : 'Write it' }}</button>
                            </div>

                            <div class="mb-3">
                                <div class="form-label small fw-semibold">Layout</div>
                                <div class="msb-layouts">
                                    @foreach(MS::LAYOUTS as $key => [$label, $hint])
                                        <input type="radio" name="layout" value="{{ $key }}" id="lay-{{ $section->id }}-{{ $key }}" @checked($section->layout === $key)>
                                        <label for="lay-{{ $section->id }}-{{ $key }}" title="{{ $hint }}"><span>{{ $layoutIcons[$key] }}</span>{{ $label }}</label>
                                    @endforeach
                                </div>
                                <div class="form-text">Changing the layout clears the content; rewrite it with the assistant.</div>
                            </div>
                        @endif

                        <div class="mb-2">
                            <label class="form-label small fw-semibold" for="title-{{ $section->id }}">Title</label>
                            <input id="title-{{ $section->id }}" type="text" name="title" maxlength="255" required class="form-control form-control-sm" value="{{ $section->title }}">
                        </div>

                        @if($section->isWritten())
                            @switch($section->layout)
                                @case('hero')
                                    <div class="mb-2"><label class="form-label small fw-semibold">Above the headline</label><input type="text" name="content[eyebrow]" maxlength="80" class="form-control form-control-sm" value="{{ $c['eyebrow'] ?? '' }}"></div>
                                    <div class="mb-2"><label class="form-label small fw-semibold">Headline</label><textarea name="content[headline]" rows="2" maxlength="160" class="form-control form-control-sm">{{ $c['headline'] ?? '' }}</textarea></div>
                                    <div class="mb-2"><label class="form-label small fw-semibold">Opening line</label><textarea name="content[lead]" rows="3" maxlength="600" class="form-control form-control-sm">{{ $c['lead'] ?? '' }}</textarea></div>
                                    <div class="mb-2"><label class="form-label small fw-semibold">Highlights</label>
                                        <div class="row g-1">@for($i = 0; $i < 4; $i++)<div class="col-6"><input type="text" name="content[highlights][]" maxlength="60" class="form-control form-control-sm" value="{{ $c['highlights'][$i] ?? '' }}"></div>@endfor</div>
                                    </div>
                                    @break
                                @case('cta')
                                    <div class="mb-2"><label class="form-label small fw-semibold">Headline</label><input type="text" name="content[headline]" maxlength="160" class="form-control form-control-sm" value="{{ $c['headline'] ?? '' }}"></div>
                                    <div class="mb-2"><label class="form-label small fw-semibold">Text</label><textarea name="content[text]" rows="2" maxlength="600" class="form-control form-control-sm">{{ $c['text'] ?? '' }}</textarea></div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-5"><label class="form-label small fw-semibold">Button</label><input type="text" name="content[button_label]" maxlength="40" class="form-control form-control-sm" value="{{ $c['button_label'] ?? '' }}"></div>
                                        <div class="col-7"><label class="form-label small fw-semibold">Goes to</label><input type="text" name="content[button_url]" maxlength="500" class="form-control form-control-sm" value="{{ $c['button_url'] ?? '' }}" placeholder="https://… or mailto:…"></div>
                                    </div>
                                    <div class="form-text mb-2">Left empty, the button emails {{ $account->owner?->name ?? 'the account owner' }}.</div>
                                    @break
                                @case('text')
                                    <div class="mb-2"><label class="form-label small fw-semibold">Text <span class="fw-normal f-light">(Markdown)</span></label><textarea name="body" rows="10" maxlength="30000" class="form-control form-control-sm font-monospace">{{ $section->body }}</textarea></div>
                                    @break
                                @default
                                    <div class="mb-2"><label class="form-label small fw-semibold">Introduction</label><textarea name="content[lead]" rows="2" maxlength="600" class="form-control form-control-sm">{{ $c['lead'] ?? '' }}</textarea></div>
                            @endswitch

                            @if(in_array($section->layout, ['cards', 'timeline', 'faq', 'plan'], true))
                                @php
                                    $key = in_array($section->layout, ['timeline', 'plan'], true) ? 'steps' : 'items';
                                    $rows = array_values($c[$key] ?? []);
                                    $noun = ['cards' => 'card', 'timeline' => 'step', 'faq' => 'question', 'plan' => 'step'][$section->layout];
                                @endphp
                                <div class="form-label small fw-semibold mt-2">{{ ucfirst($noun) }}s</div>
                                <div class="d-grid gap-2 mb-2" data-rows="{{ $key }}">
                                    @foreach($rows as $i => $row)
                                        @include('admin.proposal-studio.accounts._row', ['layout' => $section->layout, 'key' => $key, 'i' => $i, 'row' => $row])
                                    @endforeach
                                </div>
                                <template data-row-template>@include('admin.proposal-studio.accounts._row', ['layout' => $section->layout, 'key' => $key, 'i' => '__I__', 'row' => []])</template>
                                <button type="button" class="btn btn-light btn-sm mb-3" data-add-row>+ Add {{ $noun }}</button>
                            @endif

                            @if($claims->isNotEmpty())
                                <div class="form-label small fw-semibold mt-2">{{ $section->layout === 'proof' ? 'Results to show' : 'Back it up with' }} <span class="fw-normal f-light">(approved claims, word for word)</span></div>
                                <div style="max-height: 18rem; overflow: auto;" class="mb-2">
                                    @foreach($claims as $claim)
                                        <label class="msb-claim"><input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" @checked(in_array($claim->id, $chosen, true))>{{ $claim->quote }} @if($claim->topic)<span class="f-light">({{ $claim->topic }})</span>@endif</label>
                                    @endforeach
                                </div>
                            @elseif($sOffering)
                                <div class="small f-light mb-2">{{ $sOffering->name }} has no approved claims yet, so this section shows no results. <a href="{{ route('admin.proposal-studio.offerings.show', [$sOffering, 'tab' => 'claims']) }}">Review claims</a>.</div>
                            @endif

                            <details class="mb-2">
                                <summary class="small fw-semibold">Brief for the assistant</summary>
                                <textarea name="brief" rows="3" maxlength="5000" class="form-control form-control-sm mt-2">{{ $section->brief }}</textarea>
                            </details>
                        @endif

                        @if($account->opportunities->isNotEmpty())
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="opp-{{ $section->id }}">Draws on</label>
                                <select id="opp-{{ $section->id }}" name="prospect_id" class="form-select form-select-sm">
                                    <option value="">{{ $offering ? 'The site\'s product line ('.$offering->name.')' : 'No opportunity' }}</option>
                                    @foreach($account->opportunities as $opportunity)
                                        <option value="{{ $opportunity->id }}" @selected($section->prospect_id === $opportunity->id)>Opportunity: {{ $opportunity->offering->name }}{{ $opportunity->publishedProposal ? ' (proposal v'.$opportunity->publishedProposal->version.')' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="pricing_only" value="1" id="price-{{ $section->id }}" @checked($section->pricing_only)>
                            <label class="form-check-label small" for="price-{{ $section->id }}">Only people with pricing access see this section</label>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary btn-sm">Save</button>
                        </div>
                    </form>

                    <div class="d-flex gap-2 flex-wrap mt-2 pt-2 border-top">
                        @if($section->isPublished())
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.unpublish', [$account, $section]) }}">@csrf<button class="btn btn-outline-secondary btn-sm">Take off the site</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.publish', [$account, $section]) }}">@csrf<button class="btn btn-success btn-sm" @disabled($isBusy)>Publish to the site</button></form>
                        @endif
                        <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.destroy', [$account, $section]) }}" class="ms-auto" onsubmit="return confirm(@js('Delete the section "'.$section->title.'"?'));">
                            @csrf @method('DELETE')<button class="btn btn-link btn-sm text-danger">Delete</button>
                        </form>
                    </div>

                    @if($section->comments->whereNotNull('reply')->isNotEmpty())
                        <details class="mt-3"><summary class="small fw-semibold">Earlier feedback</summary>
                            @foreach($section->comments->whereNotNull('reply') as $comment)
                                <div class="small border-bottom py-2"><strong>{{ $comment->member?->label() ?? 'Someone' }}:</strong> {{ $comment->body }}<div class="f-light">Our reply: {{ $comment->reply }}</div></div>
                            @endforeach
                        </details>
                    @endif
                </div>
            @endforeach

        @elseif($tab === 'design')
            <form method="POST" action="{{ route('admin.proposal-studio.accounts.site.settings', $account) }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-offering">Product line it presents</label>
                    <select id="ms-offering" name="offering_id" class="form-select form-select-sm">
                        <option value="">None</option>
                        @foreach($allOfferings as $o)<option value="{{ $o->id }}" @selected($account->offering_id === $o->id)>{{ $o->name }} ({{ $o->vendor_name }})</option>@endforeach
                    </select>
                    <div class="form-text">The assistant writes from this product's library and approved claims.@if($offering) <a href="{{ route('admin.proposal-studio.offerings.show', $offering) }}">Open the library</a>.@endif</div>
                </div>
                <div class="mb-3">
                    <div class="form-label small fw-semibold">Theme</div>
                    <div class="msb-themes">
                        @php $swatches = ['aurora' => ['#07080c', '#eef0f4', true], 'daylight' => ['#f6f7fb', '#0e1116', false], 'editorial' => ['#f6f5f0', '#17181a', false]]; @endphp
                        @foreach(\App\Models\BusinessAccount::THEMES as $key => [$label, $hint])
                            @php [$bg, $fg, $glow] = $swatches[$key]; @endphp
                            <input type="radio" name="site_theme" value="{{ $key }}" id="th-{{ $key }}" @checked($account->theme() === $key)>
                            <label for="th-{{ $key }}" title="{{ $hint }}">
                                <div class="msb-swatch" style="background: {{ $glow ? 'radial-gradient(circle at 80% 10%, '.$account->accent().'99, transparent 60%), ' : '' }}{{ $bg }}; border: 1px solid #e5e7eb;">
                                    <i style="top: 16px; background: {{ $fg }};"></i><i style="top: 28px; right: 50%; background: {{ $account->accent() }};"></i><i style="top: 42px; right: 40%; background: {{ $fg }}55;"></i>
                                </div>
                                <div class="small text-center mt-1 fw-semibold">{{ $label }}</div>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-color">Their colour</label>
                    <div class="d-flex gap-2 align-items-center">
                        <input id="ms-color" type="color" name="brand_color" class="form-control form-control-sm" style="width: 3rem; height: 2.2rem; padding: .15rem;" oninput="document.getElementById('ms-clear-color').checked = false" value="{{ old('brand_color', $account->brand_color ?? '#d4af37') }}">
                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="clear_color" value="1" id="ms-clear-color" @checked(! $account->brand_color)><label class="form-check-label small" for="ms-clear-color">Use our gold</label></div>
                    </div>
                    @error('brand_color')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-logo">Their logo <span class="fw-normal f-light">(PNG, JPG or WebP)</span></label>
                    @if($account->logo_path)
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <img src="{{ route('admin.proposal-studio.accounts.logo', $account) }}?v={{ $account->updated_at?->timestamp }}" alt="{{ $account->name }} logo" style="max-height:44px;max-width:160px;object-fit:contain;background:#fff;border:1px solid var(--q3-border);border-radius:6px;padding:4px;">
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="ms-rm-logo"><label class="form-check-label small" for="ms-rm-logo">Remove</label></div>
                        </div>
                    @endif
                    <input id="ms-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm @error('logo') is-invalid @enderror">
                    @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-site-title">Page title</label>
                    <input id="ms-site-title" type="text" name="site_title" maxlength="255" class="form-control form-control-sm" value="{{ old('site_title', $account->site_title) }}" placeholder="{{ 'Prepared for '.$account->name }}">
                    <div class="form-text">Shown in the browser tab, and as the headline when the site has no opening section.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-site-intro">Fallback opening line</label>
                    <textarea id="ms-site-intro" name="site_intro" rows="2" maxlength="5000" class="form-control form-control-sm">{{ old('site_intro', $account->site_intro) }}</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="ms-goal">What the site is for <span class="fw-normal f-light">(the assistant reads this)</span></label>
                    <textarea id="ms-goal" name="site_goal" rows="3" maxlength="5000" class="form-control form-control-sm">{{ old('site_goal', $account->site_goal) }}</textarea>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="site_show_progress" value="1" id="ms-progress" @checked($account->site_show_progress)>
                    <label class="form-check-label small" for="ms-progress">Show them the titles of sections we are still preparing</label>
                </div>
                <button class="btn btn-primary btn-sm">Save design</button>
            </form>

        @elseif($tab === 'access')
            @if($clientUrl)<p class="small mb-2"><code style="overflow-wrap:anywhere;">{{ $clientUrl }}</code></p>@endif
            <p class="small f-light">Only people listed here can open it, each with their own emailed link (valid {{ \App\Models\MicrositeMember::LINK_DAYS }} days). Viewers read; contributors can also leave feedback and tick off the action plan. Pricing is shown only where you tick it.</p>

            @foreach($requests as $req)
                <div class="alert alert-warning small">
                    <strong>{{ $req->requestedBy?->label() ?? 'Someone' }}</strong> asked us to add <strong>{{ $req->label() }}</strong>@if($req->name) ({{ $req->email }})@endif.
                    @if($req->request_note)<div class="mt-1">"{{ $req->request_note }}"</div>@endif
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.approve', [$account, $req]) }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
                        @csrf
                        <select name="role" class="form-select form-select-sm" style="max-width: 9rem;" aria-label="Role">
                            @foreach(\App\Models\MicrositeMember::ROLES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="can_see_pricing" value="1" id="mr-p-{{ $req->id }}"><label class="form-check-label" for="mr-p-{{ $req->id }}">Pricing</label></div>
                        <button class="btn btn-success btn-sm">Approve and send link</button>
                    </form>
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.decline', [$account, $req]) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0 text-danger">Decline</button></form>
                </div>
            @endforeach

            @forelse($members as $member)
                <div class="small py-2 border-bottom">
                    <div class="d-flex justify-content-between gap-2">
                        <div style="min-width:0; overflow-wrap:anywhere;">
                            {{ $member->name ? $member->name.' · ' : '' }}{{ $member->email }}
                            <div class="f-light">{{ $member->revoked_at ? 'removed' : ($member->last_seen_at ? 'last opened '.$member->last_seen_at->diffForHumans() : 'not opened yet') }}</div>
                        </div>
                        @if($member->isActive())
                            <div class="d-flex gap-2 text-nowrap">
                                <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.resend', [$account, $member]) }}">@csrf<button class="btn btn-link btn-sm p-0">Resend</button></form>
                                <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.revoke', [$account, $member]) }}">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">Remove</button></form>
                            </div>
                        @endif
                    </div>
                    @if($member->isActive())
                        <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.update', [$account, $member]) }}" class="d-flex gap-2 align-items-center mt-1">
                            @csrf @method('PATCH')
                            <select name="role" class="form-select form-select-sm" style="max-width: 9rem;" aria-label="Role" onchange="this.form.submit()">
                                @foreach(\App\Models\MicrositeMember::ROLES as $key => $label)<option value="{{ $key }}" @selected($member->role === $key)>{{ $label }}</option>@endforeach
                            </select>
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="can_see_pricing" value="1" id="mm-p-{{ $member->id }}" @checked($member->can_see_pricing) onchange="this.form.submit()"><label class="form-check-label" for="mm-p-{{ $member->id }}">Pricing</label></div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="small f-light">Nobody yet.</p>
            @endforelse

            <form method="POST" action="{{ route('admin.proposal-studio.accounts.members.store', $account) }}" class="row g-2 mt-2">
                @csrf
                <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name" aria-label="Name" maxlength="255" value="{{ old('name') }}"></div>
                <div class="col-6"><input type="email" name="email" class="form-control form-control-sm" placeholder="Email" aria-label="Email" required maxlength="255" value="{{ old('email') }}"></div>
                <div class="col-6">
                    <select name="role" class="form-select form-select-sm" aria-label="Role">
                        @foreach(\App\Models\MicrositeMember::ROLES as $key => $label)<option value="{{ $key }}" @selected(old('role', 'contributor') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 d-flex align-items-center"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="can_see_pricing" value="1" id="mi-pricing"><label class="form-check-label small" for="mi-pricing">Can see pricing</label></div></div>
                <div class="col-12"><button class="btn btn-primary btn-sm w-100">Invite</button></div>
            </form>
            @if($account->contacts->whereNotNull('email')->isNotEmpty())
                <p class="small f-light mt-2 mb-0">Their people: {{ $account->contacts->whereNotNull('email')->map(fn ($c) => $c->name.' ('.$c->email.')')->implode(', ') }}</p>
            @endif

        @elseif($tab === 'insights')
            @php
                $totals = $reading->groupBy('microsite_section_id')->map(fn ($r) => (int) $r->sum('seconds'));
                $max = max(1, (int) $totals->max());
                $fmt = fn (int $s) => $s >= 60 ? floor($s / 60).' min '.($s % 60).' s' : $s.' s';
                $byMember = $reading->groupBy('microsite_member_id')->map(fn ($r) => ['seconds' => (int) $r->sum('seconds'), 'last' => $r->max('last')])->sortByDesc('seconds');
            @endphp
            @if($reading->isEmpty())
                <p class="small f-light">Nothing yet. Once invited people open the site, you will see here which sections they spent time on, and who.</p>
            @else
                <div class="small fw-bold text-uppercase f-light mb-2" style="letter-spacing:.06em;">Reading time by section</div>
                @foreach($sections->filter->isPublished() as $section)
                    @php $secs = $totals[$section->id] ?? 0; @endphp
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small"><span>{{ $section->title }}</span><span class="f-light">{{ $fmt($secs) }}</span></div>
                        <div class="bg-light rounded" style="height:8px;"><div class="msb-bar" style="width: {{ round(100 * $secs / $max) }}%;"></div></div>
                        @if($secs)
                            <div class="small f-light">{{ ($readingBySection[$section->id] ?? collect())->sortByDesc('seconds')->map(fn ($r) => ($memberNames[$r->microsite_member_id] ?? 'Someone').' '.$fmt((int) $r->seconds))->implode(' · ') }}</div>
                        @endif
                    </div>
                @endforeach
                <div class="small fw-bold text-uppercase f-light mt-4 mb-2" style="letter-spacing:.06em;">People</div>
                @foreach($byMember as $id => $m)
                    <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $memberNames[$id] ?? 'Someone' }}</span><span class="f-light">{{ $fmt($m['seconds']) }} · last {{ \Illuminate\Support\Carbon::parse($m['last'])->diffForHumans() }}</span></div>
                @endforeach
            @endif
            @php $events = $account->activities()->where('type', 'client')->limit(15)->get(); @endphp
            @if($events->isNotEmpty())
                <div class="small fw-bold text-uppercase f-light mt-4 mb-2" style="letter-spacing:.06em;">Recent activity</div>
                @foreach($events as $event)
                    <div class="small py-1 border-bottom">{{ $event->body }} <span class="f-light">· {{ $event->created_at->diffForHumans() }}</span></div>
                @endforeach
            @endif
        @endif
        </div>
    </div>

    {{-- ── Right: the live site ────────────────────────────────────────── --}}
    <div class="msb-stage">
        <div class="msb-chrome">
            <span class="dots"><i></i><i></i><i></i></span>
            <span class="url">{{ $clientUrl ?? 'Private site · gets its address when the first section is published' }}</span>
            <button type="button" data-device="100%" class="on" title="Desktop">🖥</button>
            <button type="button" data-device="820px" title="Tablet">▭</button>
            <button type="button" data-device="390px" title="Phone">📱</button>
            <button type="button" data-reload title="Reload">↻</button>
            <a href="{{ route('admin.proposal-studio.accounts.site.preview', $account) }}" target="_blank" title="Open in a new tab">↗</a>
        </div>
        <div class="msb-frame-wrap"><iframe id="msb-frame" src="{{ $previewUrl }}" title="Live preview of {{ $account->name }}'s site"></iframe></div>
        @if($busy)<div class="msb-writing" id="msb-writing"><span class="spinner-border spinner-border-sm"></span> The assistant is writing {{ count($busy) }} section{{ count($busy) === 1 ? '' : 's' }}…</div>@endif
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var frame = document.getElementById('msb-frame'), list = document.getElementById('msb-list'), outline = document.getElementById('msb-outline');
    var base = @json(route('admin.proposal-studio.accounts.site', $account)), current = @json($editing), ready = false, dirty = false;

    function post(msg) { if (ready && frame.contentWindow) frame.contentWindow.postMessage(msg, location.origin); }
    // The frame may say it is ready before this script runs; its load event covers that.
    function onFrameLoad() { ready = true; if (current) post({ type: 'ms-focus', id: current, instant: true }); }
    frame.addEventListener('load', onFrameLoad);
    try { if (frame.contentDocument && frame.contentDocument.readyState === 'complete' && frame.contentDocument.querySelector('[data-edit], .hero')) onFrameLoad(); } catch (e) {}

    // Open a section's editor without leaving the page.
    function open(id) {
        if (!outline) { location.href = base + '?section=' + id; return; }
        current = id;
        outline.hidden = !!id;
        document.querySelectorAll('[data-editor]').forEach(function (ed) { ed.hidden = Number(ed.dataset.editor) !== id; });
        history.replaceState(null, '', id ? base + '?section=' + id : base);
        document.querySelectorAll('.msb-tabs a').forEach(function (a) { a.classList.toggle('active', a.dataset.tab === 'build' && !id); });
        if (id) post({ type: 'ms-focus', id: id });
    }
    if (list) list.addEventListener('click', function (e) { var li = e.target.closest('.msb-item'); if (li && !e.target.closest('.msb-grip')) open(Number(li.dataset.id)); });
    document.querySelectorAll('[data-back]').forEach(function (a) { a.addEventListener('click', function (e) { if (!outline) return; e.preventDefault(); open(null); }); });

    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data) return;
        if (e.data.type === 'ms-ready') { ready = true; if (current) post({ type: 'ms-focus', id: current, instant: true }); }
        if (e.data.type === 'ms-select') open(e.data.id);
    });

    // Devices and reload.
    document.querySelectorAll('[data-device]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('[data-device]').forEach(function (x) { x.classList.toggle('on', x === b); });
            frame.style.width = b.dataset.device;
        });
    });
    document.querySelector('[data-reload]').addEventListener('click', function () { ready = false; frame.src = frame.src; });

    // Drag to reorder.
    if (list) {
        var dragged = null;
        list.addEventListener('dragstart', function (e) { dragged = e.target.closest('.msb-item'); dragged.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        list.addEventListener('dragend', function () { if (dragged) dragged.classList.remove('dragging'); list.querySelectorAll('.over').forEach(function (x) { x.classList.remove('over'); }); });
        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            var over = e.target.closest('.msb-item');
            list.querySelectorAll('.over').forEach(function (x) { if (x !== over) x.classList.remove('over'); });
            if (over && over !== dragged) over.classList.add('over');
        });
        list.addEventListener('drop', function (e) {
            e.preventDefault();
            var over = e.target.closest('.msb-item');
            if (!over || over === dragged) return;
            list.insertBefore(dragged, over);
            var ids = Array.prototype.map.call(list.querySelectorAll('.msb-item'), function (li) { return Number(li.dataset.id); });
            fetch(@json(route('admin.proposal-studio.accounts.sections.order', $account)), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                body: JSON.stringify({ ids: ids })
            }).then(function () { ready = false; frame.src = frame.src; });
        });
    }

    // Rows: add and remove cards, steps and questions.
    document.querySelectorAll('[data-add-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form'), rows = form.querySelector('[data-rows]'), tpl = form.querySelector('[data-row-template]');
            var html = tpl.innerHTML.replace(/__I__/g, 'n' + Date.now());
            rows.insertAdjacentHTML('beforeend', html);
            dirty = true;
        });
    });
    document.addEventListener('click', function (e) { var rm = e.target.closest('.msb-row .rm'); if (rm) { rm.closest('.msb-row').remove(); dirty = true; } });
    document.querySelectorAll('form[data-dirty]').forEach(function (f) {
        f.addEventListener('input', function () { dirty = true; });
        f.addEventListener('submit', function () { dirty = false; });
    });

    @if($busy)
    // While the assistant writes, refresh when it finishes (unless you are mid-edit).
    var busy = @json($busy);
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.proposal-studio.accounts.site.status', $account)), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (s) {
                    if (s.busy.length < busy.length) {
                        if (!dirty) { location.reload(); return; }
                        ready = false; frame.src = frame.src;
                        var w = document.getElementById('msb-writing'); if (w) w.textContent = 'New drafts are ready. Save, then reload to see them here.';
                    }
                    busy = s.busy; if (busy.length) poll();
                })
                .catch(poll);
        }, 4000);
    })();
    @endif
})();
</script>
@endpush
