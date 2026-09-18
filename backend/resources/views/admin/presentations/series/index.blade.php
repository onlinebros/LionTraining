@extends('layouts.admin')
@section('title', 'Repeating Schedules')
@section('page-title', 'Repeating Schedules')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.presentations.index') }}">Presentations</a></li>
    <li class="breadcrumb-item active">Repeating</li>
@endsection

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Repeating schedules</h5>
            <small class="text-muted">
                One recording, shown on the same days and times each week. Each showing still gets
                its own guest list and conversations.
            </small>
        </div>
        <a href="{{ route('admin.presentations.series.create') }}" class="btn btn-primary">New schedule</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr><th>Schedule</th><th>Recording</th><th>When</th><th>Upcoming</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($series as $s)
                    <tr>
                        <td><a href="{{ route('admin.presentations.series.edit', $s) }}" class="fw-semibold">{{ $s->title }}</a></td>
                        <td><small>{{ $s->recording?->title ?? 'Recording missing' }}</small></td>
                        <td><small>{{ $s->summary() }}</small></td>
                        <td>{{ $s->upcoming_count }}</td>
                        <td>
                            <span class="badge bg-{{ $s->is_active ? 'success' : 'secondary' }}">
                                {{ $s->is_active ? 'Running' : 'Stopped' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.presentations.series.edit', $s) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        No repeating schedules yet. One-off showings are on the
                        <a href="{{ route('admin.presentations.index') }}">Presentations</a> page.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
