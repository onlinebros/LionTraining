@extends('layouts.member')

@section('title', 'Presentations')

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h3 class="mb-1">Presentations</h3>
            <p class="text-muted mb-0">
                Scheduled showings you can invite people to. Everyone watches together, and you can
                see who of your guests turned up.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('member.presentations.live') }}" class="btn btn-outline-primary">
                Your rooms
            </a>
            @if($canSchedule)
                <a href="{{ route('member.presentations.create') }}" class="btn btn-primary">
                    Schedule your own
                </a>
            @endif
        </div>
    </div>

    @php $running = $upcoming->filter->isLive(); @endphp
    @if($running->isNotEmpty())
        <div class="alert alert-danger d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <span>
                {{ $running->count() }} {{ Str::plural('call', $running->count()) }} running right now.
            </span>
            <a href="{{ route('member.presentations.live') }}" class="btn btn-sm btn-danger">
                Open your rooms
            </a>
        </div>
    @endif

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="card">
        <div class="card-header"><h5 class="mb-0">Coming up</h5></div>
        <div class="card-body">
            @forelse($upcoming as $presentation)
                <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between py-3
                            {{ ! $loop->last ? 'border-bottom' : '' }}">
                    <div>
                        <a href="{{ route('member.presentations.show', $presentation) }}"
                           class="fw-semibold text-decoration-none d-block">{{ $presentation->title }}</a>
                        <small class="text-muted">
                            @include('partials.presentation-time', ['presentation' => $presentation]) ·
                            {{ $presentation->formattedDuration() }}
                            @if($presentation->isPersonal())
                                · <span class="badge bg-light text-dark">Yours</span>
                            @endif
                            @if($presentation->isOnDemand())
                                · <span class="badge bg-success">Always open</span>
                            @elseif($presentation->isLive())
                                · <span class="badge bg-danger">In progress</span>
                            @endif
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        @if(($myCounts[$presentation->id] ?? 0) > 0)
                            <span class="badge bg-light text-dark">
                                {{ $myCounts[$presentation->id] }}
                                {{ Str::plural('guest', $myCounts[$presentation->id]) }}
                            </span>
                        @endif
                        <a href="{{ route('member.presentations.show', $presentation) }}"
                           class="btn btn-sm btn-primary">Get my link</a>
                        @if($presentation->isPersonal() && ($presentation->isOnDemand() || ! $presentation->isLive()))
                            <form method="POST" action="{{ route('member.presentations.destroy', $presentation) }}"
                                  onsubmit="return confirm('Cancel this presentation?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Cancel</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-muted text-center py-4 mb-0">
                    Nothing scheduled right now.
                    @if($canSchedule)
                        <a href="{{ route('member.presentations.create') }}">Schedule one for your team.</a>
                    @endif
                </p>
            @endforelse
        </div>
    </div>

    @if($past->isNotEmpty())
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Past</h5></div>
            <div class="card-body">
                @foreach($past as $presentation)
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between py-2
                                {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div>
                            <a href="{{ route('member.presentations.show', $presentation) }}"
                               class="text-decoration-none">{{ $presentation->title }}</a>
                            <small class="text-muted d-block">{{ $presentation->scheduledAtLocal()->format('j M Y') }}</small>
                        </div>
                        @if(($myCounts[$presentation->id] ?? 0) > 0)
                            <span class="badge bg-light text-dark">
                                {{ $myCounts[$presentation->id] }}
                                {{ Str::plural('guest', $myCounts[$presentation->id]) }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
