@extends('layouts.admin')
@section('title', 'Presentations')
@section('page-title', 'Presentations')

@section('breadcrumb')
    <li class="breadcrumb-item active">Presentations</li>
@endsection

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Coming up</h5>
            <small class="text-muted">
                A recording from the library, shown at a set time. Members invite guests with their
                own link; each member sees only their own.
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.presentations.series.index') }}" class="btn btn-outline-primary">Repeating schedules</a>
            <a href="{{ route('admin.presentations.create') }}" class="btn btn-primary">Schedule one</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Presentation</th><th>When</th><th>Length</th><th>Registered</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($upcoming as $p)
                    <tr>
                        <td>
                            <a href="{{ route('admin.presentations.show', $p) }}" class="fw-semibold">{{ $p->title }}</a>
                            <small class="text-muted d-block">{{ $p->recording?->title ?? 'Recording missing' }}</small>
                        </td>
                        <td>@include('partials.presentation-time', ['presentation' => $p])</td>
                        <td>{{ $p->formattedDuration() }}</td>
                        <td>{{ $p->attendees_count }}</td>
                        <td>
                            <span class="badge bg-{{ $p->isLive() ? 'danger' : 'primary' }}">{{ $p->statusLabel() }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.presentations.show', $p) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Nothing scheduled.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5 class="mb-0">Past</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Presentation</th><th>When</th><th>Registered</th><th></th></tr></thead>
                <tbody>
                @forelse($past as $p)
                    <tr>
                        <td><a href="{{ route('admin.presentations.show', $p) }}">{{ $p->title }}</a></td>
                        <td>@include('partials.presentation-time', ['presentation' => $p, 'withYear' => true])</td>
                        <td>{{ $p->attendees_count }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.presentations.show', $p) }}" class="btn btn-sm btn-outline-secondary">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">None yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $past->links() }}
    </div>
</div>
@endsection
