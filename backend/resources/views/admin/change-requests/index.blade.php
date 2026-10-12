@extends('layouts.admin')

@section('title', 'Change Requests')
@section('page-title', 'Change Requests')

@section('breadcrumb')
    <li class="breadcrumb-item active">Change Requests</li>
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

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">{{ $status === 'all' ? 'All requests' : (\App\Models\ChangeRequest::STATUSES[$status] ?? ucfirst($status)) }}</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        @foreach(['all' => 'All'] + \App\Models\ChangeRequest::STATUSES as $key => $label)
                            <a href="{{ route('admin.change-requests.index', ['status' => $key]) }}"
                               class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                                @if($key !== 'all')<span class="ms-1 opacity-75">{{ $counts[$key] ?? 0 }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="card-body p-0">
                    @if($requests->isEmpty())
                        <p class="f-light small p-4 mb-0">Nothing here yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead><tr><th>#</th><th>Title</th><th>From</th><th>Status</th><th>Updated</th></tr></thead>
                                <tbody>
                                @foreach($requests as $request)
                                    <tr>
                                        <td><code>{{ $request->number }}</code></td>
                                        <td><a href="{{ route('admin.change-requests.show', $request) }}">{{ $request->title }}</a></td>
                                        <td class="small">{{ $request->user?->name }}</td>
                                        <td><span class="badge {{ $request->statusBadgeClass() }}">{{ $request->statusLabel() }}</span></td>
                                        <td class="f-light small">{{ $request->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
            {{ $requests->links() }}
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Suggest a change</h6></div>
                <div class="card-body">
                    <p class="small f-light">
                        Describe what you would like changed or added. The assistant reads the application
                        with you, asks questions, and drafts a plan. Nothing changes until the plan has been
                        reviewed and approved. Tip: the lightbulb at the top of any page starts a request
                        about that page.
                    </p>
                    <form method="POST" action="{{ route('admin.change-requests.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="ra-title">Short title</label>
                            <input id="ra-title" type="text" name="title" maxlength="200" required
                                   class="form-control @error('title') is-invalid @enderror"
                                   value="{{ old('_ra_modal') ? '' : old('title') }}" placeholder="e.g. Let partners download their team list">
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="ra-body">What would you like, and why?</label>
                            <textarea id="ra-body" name="body" rows="6" maxlength="10000" required
                                      class="form-control @error('body') is-invalid @enderror"
                                      placeholder="Who is it for, what problem does it solve, and how would you know it worked?">{{ old('_ra_modal') ? '' : old('body') }}</textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Start the conversation</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
