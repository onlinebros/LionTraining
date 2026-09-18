@extends('layouts.admin')

@section('title', $presentation->title)
@section('page-title', 'Presentation')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.presentations.index') }}">Presentations</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($presentation->title, 40) }}</li>

@endsection


@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">{{ $presentation->title }}</h5>
                    <small class="text-muted">
                        @include('partials.presentation-time', ['presentation' => $presentation, 'withYear' => true]) ·
                        {{ $presentation->formattedDuration() }} ·
                        playing “{{ $presentation->recording?->title ?? 'recording missing' }}”
                    </small>
                </div>
                <span class="badge bg-{{ $presentation->isLive() ? 'danger' : ($presentation->hasEnded() ? 'secondary' : 'primary') }}">
                    {{ $presentation->statusLabel() }}
                </span>
            </div>
            <div class="card-body">
                @if($presentation->isLive())
                    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <strong>In progress</strong> — at
                            {{ \App\Models\PresentationAttendee::clock($presentation->currentOffset() ?? 0) }}
                            of {{ $presentation->formattedDuration() }}.
                        </div>
                        <form method="POST" action="{{ route('admin.presentations.end', $presentation) }}"
                              onsubmit="return confirm('End this presentation for everyone?');">
                            @csrf
                            <button class="btn btn-sm btn-danger">End now</button>
                        </form>
                    </div>
                @elseif($presentation->isScheduled())
                    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>Starts on its own at the scheduled time.</div>
                        <form method="POST" action="{{ route('admin.presentations.start', $presentation) }}">
                            @csrf
                            <button class="btn btn-sm btn-primary">Start now</button>
                        </form>
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('presentations.watch', $presentation) }}" target="_blank"
                       class="btn btn-outline-primary btn-sm">Open the guest page</a>
                    <a href="{{ route('admin.presentations.edit', $presentation) }}"
                       class="btn btn-outline-secondary btn-sm">Edit</a>
                    <span class="flex-grow-1"></span>
                    <form method="POST" action="{{ route('admin.presentations.destroy', $presentation) }}"
                          onsubmit="return confirm('Cancel this presentation? Registrations are kept.');">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger btn-sm">Cancel presentation</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Everyone registered <span class="badge bg-light text-dark">{{ $attendees->count() }}</span></h5>
                <small class="text-muted">
                    Admins see every guest. Members see only the ones they invited.
                </small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>Guest</th><th>Invited by</th><th>Joined at</th><th>Watched</th><th>Now</th><th>Clicked join</th><th></th></tr>
                        </thead>
                        <tbody>
                        @forelse($attendees as $attendee)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $attendee->name }}</div>
                                    <small class="text-muted">{{ $attendee->email }}</small>
                                </td>
                                <td>
                                    @if($attendee->host)
                                        {{ $attendee->host->name }}
                                    @else
                                        <span class="text-muted">company link</span>
                                    @endif
                                </td>
                                <td>{{ $attendee->hasJoined() ? $attendee->formattedJoinOffset() : '—' }}</td>
                                <td>{{ $attendee->hasJoined() ? $attendee->formattedWatchTime() : '—' }}</td>
                                <td>
                                    <span class="badge bg-{{ $attendee->isWatching() ? 'success' : 'light text-dark' }}">
                                        {{ $attendee->isWatching() ? 'watching' : ($attendee->hasJoined() ? 'left' : 'not yet') }}
                                    </span>
                                </td>
                                <td>{{ $attendee->cta_clicked_at ? 'yes' : '—' }}</td>
                                <td class="text-end">
                                    {{-- Conversations live in one place. This used to open a
                                         modal of its own, which meant two consoles to keep
                                         working and only one that was. --}}
                                    <a class="btn btn-sm {{ ($unread[$attendee->id] ?? 0) ? 'btn-primary' : 'btn-outline-secondary' }}"
                                       href="{{ route('member.presentations.live') }}?guest={{ $attendee->id }}">
                                        {{ ($unread[$attendee->id] ?? 0) ? 'Reply ('.$unread[$attendee->id].')' : 'Message' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">Nobody has registered yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Who is bringing people</h5></div>
            <div class="card-body">
                @forelse($byHost->sortByDesc('total') as $row)
                    <div class="d-flex justify-content-between py-1">
                        <span>{{ $row->host?->name ?? 'Company link' }}</span>
                        <span class="fw-semibold">{{ $row->total }}</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">No registrations yet.</p>
                @endforelse
            </div>
        </div>

        @if($claims->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-1">Attribution clashes</h5>
                    <small class="text-muted">
                        A second member invited someone who was already registered. The first
                        registration keeps the guest; the second member is not told.
                    </small>
                </div>
                <div class="card-body">
                    @foreach($claims as $claim)
                        <div class="py-1 small">
                            <strong>{{ $claim->claimedBy?->name }}</strong> also invited
                            {{ $claim->attendee?->email }}
                            <span class="text-muted">
                                (kept by {{ $claim->attendee?->host?->name ?? 'the company link' }})
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Say something to everyone</h5>
                <small class="text-muted">
                    One-way. A guest replying goes to their own private thread, not to the room.
                </small>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.presentations.announce', $presentation) }}">
                    @csrf
                    <textarea name="body" rows="2" maxlength="1000" required class="form-control mb-2"
                              placeholder="We'll cover pricing next…"></textarea>
                    <button class="btn btn-sm btn-primary">Send to everyone</button>
                </form>

                @if($presentation->announcements->isNotEmpty())
                    <hr>
                    @foreach($presentation->announcements->reverse()->take(5) as $a)
                        <div class="small text-muted py-1">{{ $a->body }}</div>
                    @endforeach
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-1">Chapters</h5>
                <small class="text-muted">
                    One per line, <code>mm:ss Label</code>. Saved against the recording, so every
                    showing of it gets them. Members see which chapter a guest is watching.
                </small>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.presentations.chapters', $presentation) }}">
                    @csrf
                    <textarea name="chapters" rows="5" class="form-control mb-2"
                              style="font-family:ui-monospace,monospace;font-size:12.5px;"
                              placeholder="0:00 Welcome&#10;4:30 The problem&#10;14:02 The Compensation Plan">{{ $chapters->map(fn($c) => $c->formattedStart().' '.$c->label)->implode("\n") }}</textarea>
                    <button class="btn btn-sm btn-outline-primary">Save chapters</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-1">Your own link</h5></div>
            <div class="card-body">
                <p class="text-muted small">
                    Every guest must arrive through somebody's invite code, including head office —
                    otherwise there is no way to tell later who they belong to. This is yours; for
                    sponsoring straight to the company, use the top position's link.
                </p>
                <input type="text" class="form-control form-control-sm" readonly
                       style="font-family:ui-monospace,monospace;font-size:12px;"
                       value="{{ auth()->user()->referral_code
                           ? route('presentations.watch', ['presentation' => $presentation->slug, 'code' => auth()->user()->referral_code])
                           : 'You have no referral code yet.' }}">
                @if(auth()->user()->referral_code)
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
