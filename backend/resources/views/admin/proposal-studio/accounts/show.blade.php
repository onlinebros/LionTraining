@extends('layouts.admin')

@section('title', $account->name)
@section('page-title', $account->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item active">{{ $account->name }}</li>
@endsection

@push('styles')
<style>
    .ba-line { display:flex; gap:.75rem; padding:.6rem 0; border-bottom:1px solid var(--q3-border); }
    .ba-line:last-child { border-bottom:0; }
    .ba-type { flex:0 0 5.5rem; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; }
    .ba-line.client .ba-type { color:var(--q3-gold, #b8922a); font-weight:600; }
    .ba-line.system { opacity:.75; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

<div class="row g-3">

    <div class="col-xl-5 order-xl-2">
        {{-- The account --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Account</h6>
                <span class="badge {{ $account->stageBadgeClass() }}">{{ $account->stageLabel() }}</span>
            </div>
            <div class="card-body">
                <table class="small w-100 mb-2">
                    @foreach(array_filter(['Website' => $account->website, 'Industry' => $account->industry, 'Employees' => $account->size, 'Headquarters' => $account->headquarters, 'Owner' => $account->owner?->name]) as $label => $value)
                        <tr><td class="f-light text-nowrap pe-3 py-1">{{ $label }}</td><td>@if($label === 'Website')<a href="{{ $value }}" target="_blank" rel="noopener noreferrer">{{ $value }}</a>@else{{ $value }}@endif</td></tr>
                    @endforeach
                    @if($account->next_step)
                        <tr><td class="f-light text-nowrap pe-3 py-1">Next step</td><td>{{ $account->next_step }}@if($account->next_step_due) <span class="{{ $account->next_step_due->lt(today()) ? 'text-danger' : 'f-light' }}">· {{ $account->next_step_due->format('M j') }}</span>@endif</td></tr>
                    @endif
                </table>
                @if($account->notes)<p class="small mb-2" style="white-space:pre-line;">{{ $account->notes }}</p>@endif
                <a class="small fw-bold" data-bs-toggle="collapse" href="#ba-edit" role="button" aria-expanded="false" aria-controls="ba-edit">Edit account</a>
                <div class="collapse @if($errors->hasAny(['name', 'website', 'stage'])) show @endif" id="ba-edit">
                    <form method="POST" action="{{ route('admin.proposal-studio.accounts.update', $account) }}" class="mt-3">
                        @csrf @method('PUT')
                        @include('admin.proposal-studio.accounts._fields', ['account' => $account])
                        <div class="mt-3">
                            <label class="form-label small fw-semibold" for="ba-stage">Stage</label>
                            <select id="ba-stage" name="stage" class="form-select">
                                @foreach(\App\Models\BusinessAccount::STAGES as $key => $label)
                                    <option value="{{ $key }}" @selected($account->stage === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="btn btn-primary btn-sm mt-3">Save</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- The microsite --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Microsite</h6>
                @if($site['requests'])<span class="badge badge-light-danger">{{ $site['requests'] }} access request{{ $site['requests'] === 1 ? '' : 's' }}</span>@endif
            </div>
            <div class="card-body">
                @if($site['sections'] === 0)
                    <p class="small f-light">A private site for {{ $account->name }}, with their logo and colours. The assistant drafts each section from what we know about them and the offering's library; you publish them one by one, and invited people see it grow.</p>
                @else
                    <p class="small mb-2">{{ $site['published'] }} of {{ $site['sections'] }} sections published · {{ $site['members'] }} {{ $site['members'] === 1 ? 'person has' : 'people have' }} access.</p>
                    @if($account->site_ref)<p class="small f-light mb-2"><code>{{ route('microsite.show', $account->site_ref) }}</code></p>@endif
                @endif
                <div class="d-flex gap-2 flex-wrap">
                    <a href="{{ route('admin.proposal-studio.accounts.site', $account) }}" class="btn btn-primary btn-sm">{{ $site['sections'] ? 'Open the builder' : 'Build their microsite' }}</a>
                    @if($site['sections'])<a href="{{ route('admin.proposal-studio.accounts.site.preview', $account) }}" class="btn btn-outline-secondary btn-sm" target="_blank">Preview</a>@endif
                </div>
            </div>
        </div>

        {{-- Their people --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">People</h6></div>
            <div class="card-body">
                @forelse($account->contacts as $contact)
                    <div class="py-2 border-bottom small">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <strong>{{ $contact->name }}</strong>@if($contact->title), {{ $contact->title }}@endif
                                <span class="badge badge-light-primary ms-1">{{ $contact->roleLabel() }}</span>
                                @if($contact->is_primary)<span class="badge badge-light-success">Primary</span>@endif
                                <div class="f-light">{{ implode(' · ', array_filter([$contact->email, $contact->phone])) }}</div>
                                @if($contact->notes)<div>{{ $contact->notes }}</div>@endif
                            </div>
                            <a class="text-nowrap" data-bs-toggle="collapse" href="#bc-{{ $contact->id }}" role="button" aria-expanded="false">Edit</a>
                        </div>
                        <div class="collapse" id="bc-{{ $contact->id }}">
                            @include('admin.proposal-studio.accounts._contact', ['contact' => $contact])
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.contacts.destroy', [$account, $contact]) }}" class="mt-1" onsubmit="return confirm(@js('Remove '.$contact->name.'?'));">
                                @csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">Remove</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="small f-light">Nobody yet. Add the people involved in the decision and the part each plays.</p>
                @endforelse
                <a class="small fw-bold d-inline-block mt-2" data-bs-toggle="collapse" href="#bc-new" role="button" aria-expanded="false">Add a person</a>
                <div class="collapse @if($errors->has('role')) show @endif" id="bc-new">
                    @include('admin.proposal-studio.accounts._contact', ['contact' => null])
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-7 order-xl-1">
        {{-- Opportunities --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Opportunities</h6></div>
            <div class="card-body">
                @forelse($account->opportunities as $opportunity)
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <a href="{{ route('admin.proposal-studio.prospects.show', $opportunity) }}" class="fw-semibold">{{ $opportunity->offering->name }}</a>
                            <div class="small f-light">
                                Started {{ $opportunity->created_at->format('M j, Y') }}
                                @if($opportunity->publishedProposal) · proposal v{{ $opportunity->publishedProposal->version }} published @endif
                            </div>
                        </div>
                        <span class="badge {{ $opportunity->statusBadgeClass() }}">{{ $opportunity->statusLabel() }}</span>
                    </div>
                @empty
                    <p class="small f-light">No opportunities yet. Start one for each offering you present to them: the assistant works through discovery with you and drafts the plan, emails and proposal.</p>
                @endforelse

                @if($offerings->isNotEmpty())
                    <a class="small fw-bold d-inline-block mt-3" data-bs-toggle="collapse" href="#bo-new" role="button" aria-expanded="false">Start an opportunity</a>
                    <div class="collapse @if($errors->has('intake')) show @endif" id="bo-new">
                        <form method="POST" action="{{ route('admin.proposal-studio.accounts.opportunities.store', $account) }}" class="mt-2">
                            @csrf
                            <select name="offering_id" class="form-select form-select-sm mb-2" aria-label="Offering">
                                @foreach($offerings as $offering)<option value="{{ $offering->id }}">{{ $offering->name }}</option>@endforeach
                            </select>
                            <textarea name="intake" rows="5" maxlength="20000" required class="form-control form-control-sm @error('intake') is-invalid @enderror"
                                      placeholder="Everything you know about this opportunity: locations, sizes, HVAC, who is in the space, what prompted it, who decides…">{{ old('intake') }}</textarea>
                            <button class="btn btn-primary btn-sm mt-2">Start discovery</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        {{-- Timeline --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Timeline</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.proposal-studio.accounts.activities.store', $account) }}" class="mb-3">
                    @csrf
                    <div class="d-flex gap-2 mb-2 flex-wrap">
                        <select name="type" class="form-select form-select-sm" style="max-width: 9rem;" aria-label="Type" id="ba-type">
                            @foreach(\App\Models\BusinessActivity::TYPES as $key => $label)
                                <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="date" name="due_on" class="form-control form-control-sm" style="max-width: 11rem;" aria-label="Due on (tasks)" title="Due on (tasks only)" value="{{ old('due_on') }}">
                    </div>
                    <textarea name="body" rows="2" maxlength="10000" required class="form-control form-control-sm @error('body') is-invalid @enderror" placeholder="What happened, or what needs doing">{{ old('body') }}</textarea>
                    <button class="btn btn-primary btn-sm mt-2">Add</button>
                </form>

                @if($openTasks->isNotEmpty())
                    <h6 class="fw-bold small text-uppercase f-light">Open tasks</h6>
                    @foreach($openTasks as $task)
                        <div class="d-flex justify-content-between align-items-start gap-2 small py-1">
                            <div>
                                {{ $task->body }}
                                @if($task->due_on)<span class="{{ $task->isOverdue() ? 'text-danger' : 'f-light' }}"> · due {{ $task->due_on->format('M j') }}</span>@endif
                            </div>
                            <form method="POST" action="{{ route('admin.proposal-studio.accounts.activities.done', [$account, $task]) }}">@csrf<button class="btn btn-outline-success btn-sm py-0">Done</button></form>
                        </div>
                    @endforeach
                    <hr>
                @endif

                @forelse($activities as $activity)
                    <div class="ba-line {{ $activity->type }}">
                        <div class="ba-type f-light">{{ $activity->typeLabel() }}</div>
                        <div class="flex-grow-1 small" style="min-width:0;">
                            <div style="white-space:pre-line; overflow-wrap:anywhere;">{{ $activity->body }}@if($activity->type === 'task' && $activity->done_at) <span class="badge badge-light-success">done</span>@endif</div>
                            <div class="f-light">{{ $activity->user?->name ? $activity->user->name.' · ' : '' }}{{ $activity->created_at->format('M j, g:i a') }}</div>
                        </div>
                    </div>
                @empty
                    <p class="small f-light mb-0">Calls, meetings, notes and tasks go here, along with what their people do on the microsite.</p>
                @endforelse
            </div>
        </div>
    </div>

</div>
</div>
@endsection
