@extends('layouts.admin')
@section('title', 'Funnel presentations')
@section('page-title', 'Funnel presentations')

@section('breadcrumb')
    <li class="breadcrumb-item active">Funnels</li>
@endsection

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Flows</h5>
            <small class="text-muted">
                A set of videos a prospect steers themselves. Each video can offer choices —
                another video, or something that ends the journey — and every choice is kept.
                Whoever invited them keeps them the whole way through.
            </small>
        </div>
        <a href="{{ route('admin.funnels.create') }}" class="btn btn-primary">New flow</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Flow</th>
                        <th>Starts on</th>
                        <th>Videos</th>
                        <th>People</th>
                        <th>Who can share it</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($funnels as $funnel)
                    <tr>
                        <td>
                            <a href="{{ route('admin.funnels.show', $funnel) }}"
                               class="fw-semibold text-decoration-none">{{ $funnel->title }}</a>
                            @if(! $funnel->is_active)
                                <span class="badge bg-secondary ms-1">Switched off</span>
                            @endif
                        </td>
                        <td>
                            @if($funnel->entry)
                                {{ $funnel->entry->title }}
                            @else
                                <span class="text-warning">Nothing yet</span>
                            @endif
                        </td>
                        <td>{{ $funnel->steps_count }}</td>
                        <td>{{ $funnel->participants_count }}</td>
                        <td>
                            @if($funnel->member_shareable)
                                <span class="badge bg-success">Members</span>
                            @else
                                <span class="badge bg-light text-dark">Admins only</span>
                            @endif
                        </td>
                        <td class="text-end" style="white-space:nowrap;">
                            <a href="{{ route('admin.funnels.show', $funnel) }}"
                               class="btn btn-sm btn-outline-primary">Build</a>
                            <a href="{{ route('admin.funnels.edit', $funnel) }}"
                               class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            No flows yet. A flow is a video that ends with "what would you like to see
                            next?" — and the videos those answers lead to.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
