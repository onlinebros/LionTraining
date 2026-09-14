@extends('layouts.member')

@section('title', $contact->full_name)
@section('page-title', $contact->full_name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.crm.dashboard') }}">CRM</a></li>
    <li class="breadcrumb-item"><a href="{{ route('member.crm.contacts.index') }}">Contacts</a></li>
    <li class="breadcrumb-item active">{{ $contact->full_name }}</li>
@endsection

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="row g-3">

    {{-- ── Left column ── --}}
    <div class="col-xl-4 col-lg-5">

        {{-- Identity --}}
        <div class="card">
            <div class="card-body text-center pb-3">
                <div class="q3-avatar mx-auto mb-3" style="width:70px;height:70px;">
                    <span class="fw-bold" style="font-size:1.5rem;">
                        {{ strtoupper(substr($contact->first_name, 0, 1)) }}{{ strtoupper(substr($contact->last_name ?? '', 0, 1)) }}
                    </span>
                </div>
                <h5 class="mb-1">{{ $contact->full_name }}</h5>
                @if($contact->company)<p class="f-light mb-1">{{ $contact->company }}</p>@endif
                <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
                    <span class="badge badge-light-{{ $contact->status_color }}">{{ $contact->status_label }}</span>
                    <span class="badge badge-light-secondary">{{ \App\Models\CrmContact::$contactTypes[$contact->contact_type] ?? $contact->contact_type }}</span>
                </div>
                @if($contact->tags->isNotEmpty())
                    <div class="mb-3">
                        @foreach($contact->tags as $tag)
                            <span class="badge me-1" style="background:{{ $tag->color }};color:#fff;">{{ $tag->name }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="d-flex gap-2 justify-content-center">
                    <a href="{{ route('member.crm.contacts.edit', $contact) }}" class="btn btn-primary btn-sm">
                        <i data-feather="edit" data-width="13" data-height="13"></i> Edit
                    </a>
                    <form method="POST" action="{{ route('member.crm.contacts.destroy', $contact) }}"
                          onsubmit="return confirm('Delete {{ $contact->full_name }}?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">
                            <i data-feather="trash-2" data-width="13" data-height="13"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Details --}}
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">Details</h6></div>
            <div class="card-body">
                @if($contact->email)
                    <div class="d-flex gap-2 mb-2">
                        <i data-feather="mail" class="text-muted" style="width:15px;height:15px;flex-shrink:0;margin-top:2px;"></i>
                        <a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>
                    </div>
                @endif
                @if($contact->phone)
                    <div class="d-flex gap-2 mb-2">
                        <i data-feather="phone" class="text-muted" style="width:15px;height:15px;flex-shrink:0;margin-top:2px;"></i>
                        <a href="tel:{{ $contact->phone }}">{{ $contact->phone }}</a>
                    </div>
                @endif
                @if($contact->city || $contact->country)
                    <div class="d-flex gap-2 mb-2">
                        <i data-feather="map-pin" class="text-muted" style="width:15px;height:15px;flex-shrink:0;margin-top:2px;"></i>
                        <span>{{ implode(', ', array_filter([$contact->city, $contact->state, $contact->country])) }}</span>
                    </div>
                @endif

                <hr class="my-2">

                <div class="row g-2 small">
                    <div class="col-6 text-muted">Lead Source</div>
                    <div class="col-6">{{ \App\Models\CrmContact::$leadSources[$contact->lead_source] ?? '—' }}</div>

                    <div class="col-6 text-muted">Last Contacted</div>
                    <div class="col-6">{{ $contact->last_contacted_at?->format('M d, Y') ?? '—' }}</div>

                    <div class="col-6 text-muted">Next Follow-up</div>
                    <div class="col-6 {{ $contact->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                        {{ $contact->next_followup_at?->format('M d, Y g:i A') ?? '—' }}
                        @if($contact->isOverdue())<span class="badge badge-light-danger ms-1">Overdue</span>@endif
                    </div>

                    <div class="col-6 text-muted">Added</div>
                    <div class="col-6">{{ $contact->created_at->format('M d, Y') }}</div>
                </div>

                @if($contact->quick_note)
                    <hr class="my-2">
                    <div class="small">
                        <div class="text-muted mb-1">Notes</div>
                        <p class="mb-0">{{ $contact->quick_note }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Pending follow-ups --}}
        @if($contact->followups->whereIn('status', ['pending', 'overdue'])->count() > 0)
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0">Pending Follow-ups</h6>
                <span class="badge badge-light-warning">{{ $contact->followups->whereIn('status', ['pending', 'overdue'])->count() }}</span>
            </div>
            <div class="card-body p-0">
                @foreach($contact->followups->whereIn('status', ['pending', 'overdue']) as $fu)
                    <div class="px-3 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold small">{{ $fu->title }}</div>
                                <div class="small {{ $fu->isOverdue() ? 'text-danger' : 'text-muted' }}">
                                    {{ $fu->due_at->format('M d, Y g:i A') }}
                                </div>
                            </div>
                            <form method="POST" action="{{ route('member.crm.followups.complete', $fu) }}">
                                @csrf
                                <button class="btn btn-success btn-sm py-0 px-1">
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

    {{-- ── Right column ── --}}
    <div class="col-xl-8 col-lg-7">

        <ul class="nav nav-tabs mb-3" id="contactTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab-log">
                    <i data-feather="file-text" data-width="14" data-height="14"></i> Log Activity
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-followup">
                    <i data-feather="clock" data-width="14" data-height="14"></i> Follow-up
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-timeline">
                    <i data-feather="activity" data-width="14" data-height="14"></i> Timeline
                    <span class="badge badge-light-primary ms-1">{{ $contact->activities->count() }}</span>
                </a>
            </li>
        </ul>

        <div class="tab-content">

            {{-- Log activity tab --}}
            <div class="tab-pane fade show active" id="tab-log">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Log a Communication</h6></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('member.crm.contacts.notes.store', $contact) }}">
                            @csrf
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        @foreach(\App\Models\CrmNote::$types as $key => $meta)
                                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Date / Time</label>
                                    <input type="datetime-local" name="occurred_at" class="form-control"
                                           value="{{ now()->format('Y-m-d\TH:i') }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Title</label>
                                    <input type="text" name="title" class="form-control" placeholder="Brief subject…">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes <span class="text-danger">*</span></label>
                                    <textarea name="body" class="form-control" rows="4" required
                                              placeholder="What was discussed? What are the next steps?"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-sm">Log Note</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                @if($contact->notes->count() > 0)
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">Communication Log</h6></div>
                    <div class="card-body p-0">
                        @foreach($contact->notes->take(10) as $note)
                            <div class="d-flex gap-3 px-3 py-3 border-bottom">
                                <div class="text-center" style="width:36px;flex-shrink:0;">
                                    <i data-feather="{{ $note->type_icon }}" class="text-{{ $note->type_color }}" style="width:18px;height:18px;"></i>
                                    <div class="badge badge-light-{{ $note->type_color }} mt-1 d-block" style="font-size:.6rem;">{{ $note->type_label }}</div>
                                </div>
                                <div class="flex-grow-1">
                                    @if($note->title)<div class="fw-semibold">{{ $note->title }}</div>@endif
                                    <p class="mb-1">{{ $note->body }}</p>
                                    <small class="f-light">
                                        {{ ($note->occurred_at ?? $note->created_at)->format('M d, Y g:i A') }}
                                    </small>
                                </div>
                                <div class="flex-shrink-0">
                                    <form method="POST" action="{{ route('member.crm.contacts.notes.destroy', [$contact, $note]) }}"
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

            {{-- Follow-up tab --}}
            <div class="tab-pane fade" id="tab-followup">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Schedule a Follow-up</h6></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('member.crm.contacts.followups.store', $contact) }}">
                            @csrf
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control" required
                                           placeholder="e.g. Send follow-up email about the opportunity">
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
                                    <label class="form-label">Due <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="due_at" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="description" class="form-control" rows="2" placeholder="Prep notes…"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i data-feather="clock" data-width="13" data-height="13"></i> Schedule
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                @if($contact->followups->count() > 0)
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">All Follow-ups</h6></div>
                    <div class="card-body p-0">
                        @foreach($contact->followups as $fu)
                            <div class="d-flex align-items-start gap-3 px-3 py-2 border-bottom">
                                <div class="flex-shrink-0 mt-1">
                                    @if($fu->status === 'completed')
                                        <i data-feather="check-circle" class="text-success" style="width:18px;height:18px;"></i>
                                    @elseif($fu->isOverdue())
                                        <i data-feather="alert-circle" class="text-danger" style="width:18px;height:18px;"></i>
                                    @else
                                        <i data-feather="clock" class="text-warning" style="width:18px;height:18px;"></i>
                                    @endif
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">{{ $fu->title }}</div>
                                    <div class="small f-light">
                                        {{ $fu->type_label }} &middot;
                                        <span class="badge badge-light-{{ $fu->priority_color }}">{{ ucfirst($fu->priority) }}</span>
                                        &middot; Due {{ $fu->due_at->format('M d, Y g:i A') }}
                                    </div>
                                </div>
                                @if(in_array($fu->status, ['pending', 'overdue']))
                                    <form method="POST" action="{{ route('member.crm.followups.complete', $fu) }}">
                                        @csrf
                                        <button class="btn btn-success btn-sm py-0 px-1">
                                            <i data-feather="check" data-width="12" data-height="12"></i>
                                        </button>
                                    </form>
                                @else
                                    <span class="badge badge-light-{{ $fu->status === 'completed' ? 'success' : 'secondary' }}">
                                        {{ ucfirst($fu->status) }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- Timeline tab --}}
            <div class="tab-pane fade" id="tab-timeline">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0">Activity Timeline</h6></div>
                    <div class="card-body">
                        @forelse($contact->activities as $activity)
                            <div class="d-flex gap-3 mb-3">
                                <div class="flex-shrink-0" style="width:36px;">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto"
                                         style="width:36px;height:36px;background:rgba(115,102,255,.1);">
                                        <i data-feather="{{ $activity->icon }}" class="text-{{ $activity->icon_color }}"
                                           style="width:16px;height:16px;"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 pb-2">
                                    <div class="fw-semibold small">{{ $activity->description }}</div>
                                    @if($activity->old_value && $activity->new_value)
                                        <div class="small text-muted">
                                            <span class="badge badge-light-secondary">{{ $activity->old_value }}</span>
                                            →
                                            <span class="badge badge-light-primary">{{ $activity->new_value }}</span>
                                        </div>
                                    @endif
                                    <small class="f-light">
                                        {{ $activity->created_at->format('M d, Y g:i A') }}
                                        ({{ $activity->created_at->diffForHumans() }})
                                    </small>
                                </div>
                            </div>
                        @empty
                            <p class="text-center f-light">No activity yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>
@endsection
