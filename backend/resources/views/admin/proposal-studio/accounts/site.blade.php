@extends('layouts.admin')

@section('title', 'Microsite · '.$account->name)
@section('page-title', $account->name.': microsite')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.show', $account) }}">{{ $account->name }}</a></li>
    <li class="breadcrumb-item active">Microsite</li>
@endsection

@php
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $requests = $account->members->filter->isPendingRequest();
    $members = $account->members->reject->isPendingRequest()->filter(fn ($m) => $m->status === 'invited');
@endphp

@push('styles')
<style>
    .ms-body { max-height: 14rem; overflow: auto; border:1px solid var(--q3-border); border-radius:.375rem; padding:.75rem 1rem; background:var(--q3-surface-2); }
    .ms-body > :last-child { margin-bottom:0; }
    .ms-claims { max-height: 16rem; overflow:auto; }
    .ms-swatch { width:2.5rem; height:2rem; padding:.15rem; }
    .ms-logo { max-height:48px; max-width:160px; object-fit:contain; background:#fff; border:1px solid var(--q3-border); border-radius:.25rem; padding:4px; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

<div class="row g-3">

    {{-- Sections --}}
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold">Sections</h6>
                <div class="d-flex gap-2 flex-wrap">
                    @if($sections->isNotEmpty())
                        <form method="POST" action="{{ route('admin.proposal-studio.accounts.site.draft-empty', $account) }}">@csrf<button class="btn btn-outline-primary btn-sm">Draft every empty section</button></form>
                        <a href="{{ route('admin.proposal-studio.accounts.site.preview', $account) }}" class="btn btn-outline-secondary btn-sm" target="_blank">Preview</a>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @if($sections->isEmpty())
                    <p class="mb-2">Start with the usual outline: welcome, their challenges, our approach, evidence, @if($account->opportunities->contains(fn ($o) => $o->publishedProposal))their proposal, @endif questions and next steps. Each is linked to {{ $account->opportunities->first()?->offering->name ?? 'their opportunity' }} so the assistant knows what to draw on.</p>
                    @if($account->opportunities->isEmpty())
                        <p class="small text-warning">This account has no opportunity yet, so the assistant can only write the welcome and their challenges. <a href="{{ route('admin.proposal-studio.accounts.show', $account) }}">Start an opportunity</a> first for the full outline.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.site.starter', $account) }}">@csrf<button class="btn btn-primary btn-sm">Add the usual sections</button></form>
                @endif

                @foreach($sections as $section)
                    @php
                        $isBusy = in_array($section->id, $busy, true);
                        $claims = $section->opportunity ? ($claimsByOffering[$section->opportunity->offering_id] ?? collect()) : collect();
                        $chosen = array_map('intval', $section->claim_ids ?? []);
                        $open = $errors->any() && (int) old('section_id') === $section->id;
                    @endphp
                    <div class="border rounded p-3 mb-3" id="ms-{{ $section->id }}">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div class="d-flex align-items-start gap-2">
                                <div class="d-flex flex-column">
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.move', [$account, $section]) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-link btn-sm p-0 lh-1" @disabled($loop->first) aria-label="Move up">▲</button></form>
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.move', [$account, $section]) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-link btn-sm p-0 lh-1" @disabled($loop->last) aria-label="Move down">▼</button></form>
                                </div>
                                <div>
                                    <div class="fw-semibold">{{ $section->title }}</div>
                                    <div class="small f-light">
                                        {{ $section->kindLabel() }}
                                        @if($section->opportunity) · {{ $section->opportunity->offering->name }} @endif
                                        @if($section->pricing_only) · pricing access only @endif
                                        @if($section->drafted_at) · drafted {{ $section->drafted_at->diffForHumans() }} @endif
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                @if($isBusy)<span class="badge badge-light-info"><span class="spinner-border spinner-border-sm me-1" role="status"></span>Assistant writing</span>@endif
                                <span class="badge {{ $section->isPublished() ? 'badge-light-success' : 'badge-light-secondary' }}">{{ $section->isPublished() ? 'Published' : 'Draft' }}</span>
                                @if($section->isWritten())
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.draft', [$account, $section]) }}">@csrf<button class="btn btn-outline-primary btn-sm" @disabled($isBusy)>{{ $section->body ? 'Redraft' : 'Draft with AI' }}</button></form>
                                @endif
                                @if($section->isPublished())
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.unpublish', [$account, $section]) }}">@csrf<button class="btn btn-outline-secondary btn-sm">Unpublish</button></form>
                                @else
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.publish', [$account, $section]) }}">@csrf<button class="btn btn-success btn-sm" @disabled($isBusy)>Publish</button></form>
                                @endif
                            </div>
                        </div>

                        @if($section->isWritten() && $section->body)
                            <div class="ms-body mt-2 small">{!! $md($section->body) !!}</div>
                        @elseif($section->kind === 'proposal')
                            <p class="small mt-2 mb-0">{{ $section->opportunity?->publishedProposal ? 'Shows version '.$section->opportunity->publishedProposal->version.' of the proposal, as published. Pricing in it is shown only to people with pricing access.' : 'The linked opportunity has no published proposal yet.' }}</p>
                        @elseif($section->kind === 'documents')
                            <p class="small mt-2 mb-0">Lists {{ $section->opportunity?->offering->name ?? 'the offering' }}'s shareable documents for download.</p>
                        @endif
                        @if(count($chosen))<div class="small f-light mt-1">{{ count($chosen) }} approved claim{{ count($chosen) === 1 ? '' : 's' }} shown word for word below the text.</div>@endif

                        @foreach($section->comments as $comment)
                            <div class="alert {{ $comment->reply ? 'alert-light' : 'alert-warning' }} small mt-2 mb-0">
                                <strong>{{ $comment->member?->label() ?? 'Someone' }}</strong> <span class="f-light">· {{ $comment->created_at->diffForHumans() }}</span>
                                <div style="white-space:pre-line;">{{ $comment->body }}</div>
                                @if($comment->reply)
                                    <div class="mt-1"><span class="f-light">Our reply:</span> {{ $comment->reply }}</div>
                                @else
                                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.comments.reply', [$account, $comment]) }}" class="mt-2">
                                        @csrf
                                        <textarea name="reply" rows="2" class="form-control form-control-sm mb-2" required maxlength="10000" placeholder="Your reply. They are emailed, and it shows under their comment."></textarea>
                                        <button class="btn btn-primary btn-sm">Reply</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach

                        <a class="small fw-bold d-inline-block mt-2" data-bs-toggle="collapse" href="#ms-edit-{{ $section->id }}" role="button" aria-expanded="{{ $open ? 'true' : 'false' }}">Edit</a>
                        <div class="collapse @if($open) show @endif" id="ms-edit-{{ $section->id }}">
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.update', [$account, $section]) }}" class="mt-2">
                                @csrf @method('PUT')
                                <input type="hidden" name="section_id" value="{{ $section->id }}">
                                <div class="row g-2">
                                    <div class="col-md-7">
                                        <label class="form-label small fw-semibold" for="ms-title-{{ $section->id }}">Title</label>
                                        <input id="ms-title-{{ $section->id }}" type="text" name="title" maxlength="255" required class="form-control form-control-sm" value="{{ $section->title }}">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label small fw-semibold" for="ms-opp-{{ $section->id }}">Draws on</label>
                                        <select id="ms-opp-{{ $section->id }}" name="prospect_id" class="form-select form-select-sm">
                                            <option value="">No opportunity</option>
                                            @foreach($account->opportunities as $opportunity)
                                                <option value="{{ $opportunity->id }}" @selected($section->prospect_id === $opportunity->id)>{{ $opportunity->offering->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if($section->isWritten())
                                        <div class="col-12">
                                            <label class="form-label small fw-semibold" for="ms-brief-{{ $section->id }}">Brief for the assistant</label>
                                            <textarea id="ms-brief-{{ $section->id }}" name="brief" rows="2" maxlength="5000" class="form-control form-control-sm" placeholder="What this section should say, and to whom">{{ $section->brief }}</textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-semibold" for="ms-body-{{ $section->id }}">Text <span class="fw-normal f-light">(Markdown. No performance, safety, health or test results here: choose approved claims below instead.)</span></label>
                                            <textarea id="ms-body-{{ $section->id }}" name="body" rows="9" maxlength="30000" class="form-control form-control-sm font-monospace">{{ $section->body }}</textarea>
                                        </div>
                                    @endif
                                    @if($claims->isNotEmpty() && $section->isWritten())
                                        <div class="col-12">
                                            <div class="form-label small fw-semibold">Approved claims to show, word for word</div>
                                            <div class="ms-claims border rounded p-2">
                                                @foreach($claims as $claim)
                                                    <div class="form-check small">
                                                        <input class="form-check-input" type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" id="ms-c-{{ $section->id }}-{{ $claim->id }}" @checked(in_array($claim->id, $chosen, true))>
                                                        <label class="form-check-label" for="ms-c-{{ $section->id }}-{{ $claim->id }}">{{ $claim->quote }} <span class="f-light">({{ $claim->topic }})</span></label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @elseif($section->opportunity && $section->isWritten())
                                        <div class="col-12 small f-light">{{ $section->opportunity->offering->name }} has no approved claims yet, so this section can state no results. Approve claims under Offerings &amp; knowledge.</div>
                                    @endif
                                    <div class="col-12">
                                        <div class="form-check small">
                                            <input class="form-check-input" type="checkbox" name="pricing_only" value="1" id="ms-price-{{ $section->id }}" @checked($section->pricing_only)>
                                            <label class="form-check-label" for="ms-price-{{ $section->id }}">Only people with pricing access see this section</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-2 flex-wrap">
                                    <button class="btn btn-primary btn-sm">Save</button>
                                    @if($section->isWritten())<button class="btn btn-outline-primary btn-sm" name="then" value="draft" @disabled($isBusy)>Save and redraft from the brief</button>@endif
                                </div>
                            </form>
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.destroy', [$account, $section]) }}" class="mt-2" onsubmit="return confirm(@js('Delete the section "'.$section->title.'"?'));">
                                @csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">Delete section</button>
                            </form>
                        </div>
                    </div>
                @endforeach

                @if($sections->isNotEmpty())
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.sections.store', $account) }}" class="d-flex gap-2 flex-wrap align-items-center">
                        @csrf
                        <select name="kind" class="form-select form-select-sm" style="max-width: 14rem;" aria-label="Kind of section">
                            @foreach(\App\Models\MicrositeSection::KINDS as $key => [$label])
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($account->opportunities->count() > 1)
                            <select name="prospect_id" class="form-select form-select-sm" style="max-width: 16rem;" aria-label="Draws on">
                                @foreach($account->opportunities as $opportunity)<option value="{{ $opportunity->id }}">{{ $opportunity->offering->name }}</option>@endforeach
                            </select>
                        @endif
                        <button class="btn btn-outline-secondary btn-sm">Add section</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        {{-- Access --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Who can open it</h6>
                @if($requests->isNotEmpty())<span class="badge badge-light-danger">{{ $requests->count() }} to approve</span>@endif
            </div>
            <div class="card-body">
                @if($account->site_ref)
                    <p class="small mb-2"><code>{{ route('microsite.show', $account->site_ref) }}</code></p>
                @endif
                <p class="small f-light">Only people listed here can open it, each with their own emailed link (valid {{ \App\Models\MicrositeMember::LINK_DAYS }} days). Viewers read; contributors can also leave feedback on sections. Pricing is shown only where you tick it.</p>

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
            </div>
        </div>

        {{-- Look --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Look and welcome</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.proposal-studio.accounts.site.settings', $account) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="ms-site-title">Title</label>
                        <input id="ms-site-title" type="text" name="site_title" maxlength="255" class="form-control form-control-sm" value="{{ old('site_title', $account->site_title) }}" placeholder="{{ 'Prepared for '.$account->name }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="ms-site-intro">Opening line</label>
                        <textarea id="ms-site-intro" name="site_intro" rows="3" maxlength="5000" class="form-control form-control-sm" placeholder="One or two sentences under the title">{{ old('site_intro', $account->site_intro) }}</textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="ms-logo">Their logo <span class="fw-normal f-light">(PNG, JPG or WebP)</span></label>
                        @if($account->logo_path)
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <img src="{{ route('admin.proposal-studio.accounts.logo', $account) }}?v={{ $account->updated_at?->timestamp }}" alt="{{ $account->name }} logo" class="ms-logo">
                                <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="ms-rm-logo"><label class="form-check-label small" for="ms-rm-logo">Remove</label></div>
                            </div>
                        @endif
                        <input id="ms-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm @error('logo') is-invalid @enderror">
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="ms-color">Their colour <span class="fw-normal f-light">(the header band)</span></label>
                        <div class="d-flex gap-2 align-items-center">
                            <input id="ms-color" type="color" name="brand_color" class="form-control form-control-sm ms-swatch" oninput="document.getElementById('ms-clear-color').checked = false" value="{{ old('brand_color', $account->brand_color ?? '#0b0b0c') }}">
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="clear_color" value="1" id="ms-clear-color" @checked(! $account->brand_color)><label class="form-check-label small" for="ms-clear-color">Use ours</label></div>
                        </div>
                        @error('brand_color')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="site_show_progress" value="1" id="ms-progress" @checked($account->site_show_progress)>
                        <label class="form-check-label small" for="ms-progress">Show them the titles of sections we are still preparing</label>
                    </div>
                    <button class="btn btn-primary btn-sm">Save</button>
                </form>
            </div>
        </div>
    </div>

</div>
</div>

@if($busy)
@push('scripts')
<script>
    // Reload once the assistant has finished writing.
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('admin.proposal-studio.accounts.site.status', $account)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (s) { if (s.busy) { poll(); } else { window.location.reload(); } })
                .catch(poll);
        }, 5000);
    })();
</script>
@endpush
@endif
@endsection
