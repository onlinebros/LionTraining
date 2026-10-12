@extends('layouts.admin')

@section('title', 'Review Requests')
@section('page-title', 'Review Requests')

@section('breadcrumb')
    <li class="breadcrumb-item active">Review Requests</li>
@endsection

@section('content')
<div class="container-fluid">

    @foreach(['success' => 'success', 'error' => 'danger'] as $flash => $class)
        @if(session($flash))
            <div class="alert alert-{{ $class }} alert-dismissible fade show" role="alert">
                {{ session($flash) }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
    @endforeach

    <p class="small f-light">
        The change requests kept on <strong>{{ $upstream }}</strong>, where super admins make them.
        This is the only place they are decided. Deciding records it there; it builds nothing.
    </p>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">{{ $status === 'all' ? 'All requests' : (\App\Models\ChangeRequest::STATUSES[$status] ?? ucfirst($status)) }}</h5>
            <div class="d-flex gap-2 flex-wrap">
                @foreach(\App\Models\ChangeRequest::STATUSES + ['all' => 'All'] as $key => $label)
                    <a href="{{ route('admin.review-requests.index', ['status' => $key]) }}"
                       class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                        @if($key !== 'all')<span class="ms-1 opacity-75">{{ $counts[$key] ?? 0 }}</span>@endif
                    </a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            @if($unavailable)
                <p class="text-warning small p-4 mb-0">{{ $unavailable }}</p>
            @elseif($requests->isEmpty())
                <p class="f-light small p-4 mb-0">Nothing here.</p>
            @else
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>#</th><th>Title</th><th>From</th><th>Status</th><th>Plan</th><th>Updated</th></tr></thead>
                        <tbody>
                        @foreach($requests as $request)
                            <tr>
                                <td><code>{{ $request->number }}</code></td>
                                <td><a href="{{ route('admin.review-requests.show', $request->id) }}">{{ $request->title }}</a></td>
                                <td class="small">{{ $request->user?->name }}</td>
                                <td><span class="badge {{ $request->statusBadgeClass() }}">{{ $request->statusLabel() }}</span></td>
                                <td class="small">{{ $request->plan ? 'Drafted' : '—' }}</td>
                                <td class="f-light small">{{ $request->updated_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
