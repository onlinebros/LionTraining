@extends('layouts.admin')

@section('title', $crmContact->full_name)
@section('page-title', $crmContact->full_name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.dashboard') }}">CRM</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.contacts.index') }}">Contacts</a></li>
    <li class="breadcrumb-item active">{{ $crmContact->full_name }}</li>
@endsection

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="row g-3">

    {{-- ── Left: Contact card ── --}}
    <div class="col-xl-4 col-lg-5">

        {{-- Identity card --}}
        <div class="card">
            <div class="card-body text-center pb-3">
                <div class="q3-avatar mx-auto mb-3" style="width:70px;height:70px;">
                    <span class="fw-bold" style="font-size:1.5rem;">
                        {{ strtoupper(substr($crmContact->first_name, 0, 1)) }}{{ strtoupper(substr($crmContact->last_name ?? '', 0, 1)) }}
                    </span>
                </div>
                <h5 class="mb-1">{{ $crmContact->full_name }}</h5>
                @if($crmContact->company)
                    <p class="f-light mb-1">{{ $crmContact->company }}</p>
                @endif
                <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
                    <span class="badge badge-light-{{ $crmContact->status_color }}">{{ $crmContact->status_label }}</span>
                    <span class="badge badge-light-secondary">{{ \App\Models\CrmContact::$contactTypes[$crmContact->contact_type] ?? $crmContact->contact_type }}</span>
                </div>
                @if($crmContact->tags->isNotEmpty())
                    <div class="mb-3">
                        @foreach($crmContact->tags as $tag)
                            <span class="badge me-1" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="d-flex gap-2 justify-content-center">
                    <a href="{{ route('admin.crm.contacts.edit', $crmContact) }}" class="btn btn-primary btn-sm">
                        <i data-feather="edit" data-width="13" data-height="13"></i> Edit
                    </a>
                    <form method="POST" action="{{ route('admin.crm.contacts.destroy', $crmContact) }}"
                          onsubmit="return confirm('Delete {{ $crmContact->full_name }}?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">
                            <i data-feather="trash-2" data-width="13" data-height="13"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Contact details --}}
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">Details</h6></div>
            <div class="card-body">
                @if($crmContact->email)
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i data-feather="mail" class="text-muted mt-1" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <a href="mailto:{{ $crmContact->email }}">{{ $crmContact->email }}</a>
                    </div>
                @endif
                @if($crmContact->phone)
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i data-feather="phone" class="text-muted mt-1" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <a href="tel:{{ $crmContact->phone }}">{{ $crmContact->phone }}</a>
                    </div>
                @endif
                @if($crmContact->website)
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i data-feather="globe" class="text-muted mt-1" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <a href="{{ $crmContact->website }}" target="_blank" rel="noopener">{{ $crmContact->website }}</a>
                    </div>
                @endif
                @if($crmContact->city || $crmContact->country)
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i data-feather="map-pin" class="text-muted mt-1" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <span>{{ implode(', ', array_filter([$crmContact->city, $crmContact->state, $crmContact->country])) }}</span>
                    </div>
                @endif

                <hr class="my-2">

                <div class="row g-2 small">
                    <div class="col-6 text-muted">Lead Source</div>
                    <div class="col-6">{{ \App\Models\CrmContact::$leadSources[$crmContact->lead_source] ?? '—' }}</div>

                    <div class="col-6 text-muted">Owner</div>
                    <div class="col-6">{{ $crmContact->owner?->name ?? '—' }}</div>

                    <div class="col-6 text-muted">Assigned To</div>
                    <div class="col-6">{{ $crmContact->assignee?->name ?? 'Owner' }}</div>

                    <div class="col-6 text-muted">Last Contacted</div>
                    <div class="col-6">{{ $crmContact->last_contacted_at?->format('M d, Y') ?? '—' }}</div>

                    <div class="col-6 text-muted">Next Follow-up</div>
                    <div class="col-6 {{ $crmContact->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                        {{ $crmContact->next_followup_at?->format('M d, Y g:i A') ?? '—' }}
                        @if($crmContact->isOverdue())
                            <span class="badge badge-light-danger ms-1">Overdue</span>
                        @endif
                    </div>

                    <div class="col-6 text-muted">Created</div>
                    <div class="col-6">{{ $crmContact->created_at->format('M d, Y') }}</div>
                </div>

                @if($crmContact->quick_note)
                    <hr class="my-2">
                    <div class="small">
                        <div class="text-muted mb-1">Note</div>
                        <p class="mb-0">{{ $crmContact->quick_note }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Pending follow-ups summary --}}
        @if($crmContact->followups->whereIn('status', ['pending', 'overdue'])->count() > 0)
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">Pending Follow-ups</h6>
                <span class="badge badge-light-warning">{{ $crmContact->followups->whereIn('status', ['pending', 'overdue'])->count() }}</span>
            </div>
            <div class="card-body p-0">
                @foreach($crmContact->followups->whereIn('status', ['pending', 'overdue']) as $fu)
                    <div class="px-3 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold small">{{ $fu->title }}</div>
                                <div class="small {{ $fu->isOverdue() ? 'text-danger' : 'text-muted' }}">
                                    {{ $fu->due_at->format('M d, Y g:i A') }}
                                    @if($fu->isOverdue()) (overdue) @endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('admin.crm.followups.complete', $fu) }}">
                                @csrf
                                <button class="btn btn-success btn-sm py-0 px-1" title="Mark complete">
                                    <i data-feather="check" data-width="12" data-height="12"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>

    {{-- ── Right: Activity tabs ── --}}
    <div class="col-xl-8 col-lg-7">

        {{-- Tab nav --}}
        <ul class="nav nav-tabs mb-3" id="contactTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab-log-note">
                    <i data-feather="file-text" data-width="14" data-height="14"></i> Log Activity
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-followup">
                    <i data-feather="clock" data-width="14" data-height="14"></i> Schedule Follow-up
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-timeline">
                    <i data-feather="activity" data-width="14" data-height="14"></i> Timeline
                    <span class="badge badge-light-primary ms-1">{{ $crmContact->activities->count() }}</span>
                </a>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ── Log note tab ── --}}
            <div class="tab-pane fade show active" id="tab-log-note">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Log Communication / Note</h6></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.crm.contacts.notes.store', $crmContact) }}">
                            @csrf
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        @foreach(\App\Models\CrmNote::$types as $key => $meta)
                                            <option value="{{ $key }}">
                                                {{ $meta['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Date / Time</label>
                                    <input type="datetime-local" name="occurred_at" class="form-control"
                                           value="{{ now()->format('Y-m-d\TH:i') }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Title (optional)</label>
                                    <input type="text" name="title" class="form-control" placeholder="Brief title…">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes <span class="text-danger">*</span></label>
                                    <textarea name="body" class="form-control" rows="4" required
                                              placeholder="What happened? What was discussed?"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i data-feather="save" data-width="13" data-height="13"></i> Log Note
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Recent notes --}}
                @if($crmContact->notes->count() > 0)
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">Communication Log</h6></div>
                    <div class="card-body p-0">
                        @foreach($crmContact->notes->take(10) as $note)
                            <div class="d-flex gap-3 px-3 py-3 border-bottom">
                                <div class="flex-shrink-0 text-center" style="width:36px;">
                                    <i data-feather="{{ $note->type_icon }}"
                                       class="text-{{ $note->type_color }}"
                                       style="width:18px;height:18px;"></i>
                                    <div class="badge badge-light-{{ $note->type_color }} mt-1 d-block" style="font-size:.6rem;">
                                        {{ $note->type_label }}
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    @if($note->title)
                                        <div class="fw-semibold">{{ $note->title }}</div>
                                    @endif
                                    <p class="mb-1">{{ $note->body }}</p>
                                    <small class="f-light">
                                        {{ $note->user?->name ?? 'System' }} &middot;
                                        {{ ($note->occurred_at ?? $note->created_at)->format('M d, Y g:i A') }}
                                    </small>
                                </div>
                                <div class="flex-shrink-0">
                                    <form method="POST" action="{{ route('admin.crm.contacts.notes.destroy', [$crmContact, $note]) }}"
                                          onsubmit="return confirm('Delete this note?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm py-0 px-1">
                                            <i data-feather="trash-2" data-width="12" data-height="12"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- ── Schedule follow-up tab ── --}}
            <div class="tab-pane fade" id="tab-followup">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Schedule a Follow-up</h6></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.crm.contacts.followups.store', $crmContact) }}">
                            @csrf
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control" required placeholder="e.g. Follow-up call about pricing">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        @foreach(\App\Models\CrmFollowup::$types as $key => $meta)
                                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        @foreach(\App\Models\CrmFollowup::$priorities as $key => $meta)
                                            <option value="{{ $key }}" {{ $key === 'medium' ? 'selected' : '' }}>{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label">Due Date <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="due_at" class="form-control" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Assign To</label>
                                    <select name="assigned_to" class="form-select">
                                        <option value="">— Owner —</option>
                                        @foreach($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description (optional)</label>
                                    <textarea name="description" class="form-control" rows="3"
                                              placeholder="Additional context or prep notes…"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i data-feather="clock" data-width="13" data-height="13"></i> Schedule Follow-up
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- All follow-ups list --}}
                @if($crmContact->followups->count() > 0)
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">All Follow-ups</h6></div>
                    <div class="card-body p-0">
                        @foreach($crmContact->followups as $fu)
                            <div class="d-flex align-items-start gap-3 px-3 py-2 border-bottom">
                                <div class="flex-shrink-0">
                                    @if($fu->status === 'completed')
                                        <i data-feather="check-circle" class="text-success" style="width:18px;height:18px;"></i>
                                    @elseif($fu->isOverdue())
                                        <i data-feather="alert-circle" class="text-danger" style="width:18px;height:18px;"></i>
                                    @else
                                        <i data-feather="clock" class="text-warning" style="width:18px;height:18px;"></i>
                                    @endif
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ $fu->title }}</div>
                                    <div class="small f-light">
                                        {{ $fu->type_label }} &middot;
                                        <span class="badge badge-light-{{ $fu->priority_color }}">{{ ucfirst($fu->priority) }}</span>
                                        &middot; Due {{ $fu->due_at->format('M d, Y g:i A') }}
                                    </div>
                                    @if($fu->completion_note)
                                        <div class="small text-muted mt-1">{{ $fu->completion_note }}</div>
                                    @endif
                                </div>
                                <div class="flex-shrink-0 d-flex gap-1">
                                    @if(in_array($fu->status, ['pending', 'overdue']))
                                        <form method="POST" action="{{ route('admin.crm.followups.complete', $fu) }}">
                                            @csrf
                                            <button class="btn btn-success btn-sm py-0 px-1" title="Mark complete">
                                                <i data-feather="check" data-width="12" data-height="12"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.crm.followups.cancel', $fu) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-secondary btn-sm py-0 px-1" title="Cancel">
                                                <i data-feather="x" data-width="12" data-height="12"></i>
                                            </button>
                                        </form>
                                    @else
                                        <span class="badge badge-light-{{ $fu->status === 'completed' ? 'success' : 'secondary' }}">
                                            {{ ucfirst($fu->status) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- ── Timeline tab ── --}}
            <div class="tab-pane fade" id="tab-timeline">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Activity Timeline</h6></div>
                    <div class="card-body">
                        @forelse($crmContact->activities as $activity)
                            <div class="d-flex gap-3 mb-3">
                                <div class="flex-shrink-0 text-center" style="width:36px;">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto"
                                         style="width:36px;height:36px;background:var(--bs-{{ $activity->icon_color }}-bg,rgba(108,117,125,.15));">
                                        <i data-feather="{{ $activity->icon }}"
                                           class="text-{{ $activity->icon_color }}"
                                           style="width:16px;height:16px;"></i>
                                    </div>
                                    @if(!$loop->last)
                                        <div style="width:2px;background:var(--q3-border-strong);margin:4px auto 0;height:calc(100% - 36px);min-height:16px;"></div>
                                    @endif
                                </div>
                                <div class="flex-grow-1 pb-2">
                                    <div class="fw-semibold">{{ $activity->description }}</div>
                                    @if($activity->old_value && $activity->new_value)
                                        <div class="small text-muted">
                                            <span class="badge badge-light-secondary">{{ $activity->old_value }}</span>
                                            <i data-feather="arrow-right" data-width="12" data-height="12"></i>
                                            <span class="badge badge-light-primary">{{ $activity->new_value }}</span>
                                        </div>
                                    @endif
                                    <small class="f-light">
                                        {{ $activity->user?->name ?? 'System' }} &middot;
                                        {{ $activity->created_at->format('M d, Y g:i A') }}
                                        ({{ $activity->created_at->diffForHumans() }})
                                    </small>
                                </div>
                            </div>
                        @empty
                            <p class="text-center f-light">No activity recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>{{-- /tab-content --}}
    </div>

</div>
@endsection
