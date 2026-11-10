@extends('layouts.admin')
@section('title', 'Live links')
@section('page-title', 'Live links')

@section('breadcrumb')
    <li class="breadcrumb-item active">Live links</li>
@endsection

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="card">
    <div class="card-header">
        <h5 class="mb-1">Where each link goes</h5>
        <small class="text-muted">
            Give people the short address once, on a flyer, in a text or in a post. When the call
            moves, change the destination here and the same address follows it straight away.
            A link with no destination, or switched off, shows a "nothing live right now" page.
        </small>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="min-width:220px;">Address</th>
                        <th style="min-width:180px;">Name</th>
                        <th style="min-width:320px;">Goes to</th>
                        <th>On</th>
                        <th>Clicks</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($links as $link)
                    @php $formId = 'live-link-' . $link->id; @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <a href="{{ $link->publicUrl() }}" target="_blank" rel="noopener"
                                   class="fw-semibold text-break">{{ $link->publicUrl() }}</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                                        data-copy="{{ $link->publicUrl() }}">Copy</button>
                            </div>
                            @if($link->isLive())
                                <span class="badge bg-success">Live</span>
                            @elseif(! $link->is_active)
                                <span class="badge bg-secondary">Switched off</span>
                            @else
                                <span class="badge bg-warning text-dark">Holding: no destination</span>
                            @endif
                        </td>
                        <td>
                            <input form="{{ $formId }}" name="label" class="form-control form-control-sm"
                                   value="{{ $link->label }}" maxlength="100" required>
                        </td>
                        <td>
                            <input form="{{ $formId }}" name="destination_url" type="url"
                                   class="form-control form-control-sm"
                                   value="{{ $link->destination_url }}" maxlength="2000"
                                   placeholder="https://us02web.zoom.us/j/…">
                            @if($link->editor)
                                <div class="text-muted small mt-1">
                                    Changed by {{ $link->editor->name }}, {{ $link->updated_at->diffForHumans() }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="form-check form-switch">
                                <input form="{{ $formId }}" class="form-check-input" type="checkbox"
                                       name="is_active" value="1" @checked($link->is_active)>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            {{ number_format($link->clicks) }}
                            @if($link->last_clicked_at)
                                <div class="text-muted small">last {{ $link->last_clicked_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <form id="{{ $formId }}" method="POST" action="{{ route('admin.live-links.update', $link) }}" class="d-inline">
                                @csrf @method('PUT')
                                <button class="btn btn-sm btn-primary">Save</button>
                            </form>
                            <form method="POST" action="{{ route('admin.live-links.destroy', $link) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $link->publicUrl() }}? Anyone who still has it will get a not-found page.');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-1">Add a link</h5>
        <small class="text-muted">Every address starts with <code>{{ $prefix }}</code>, then lowercase letters, numbers and hyphens.</small>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.live-links.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label small">Address</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">{{ $base }}/</span>
                    <input name="slug" class="form-control" value="{{ old('slug', $prefix) }}" maxlength="60" required>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Name</label>
                <input name="label" class="form-control form-control-sm" value="{{ old('label') }}" maxlength="100" required
                       placeholder="Live Q&amp;A">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Goes to (optional for now)</label>
                <input name="destination_url" type="url" class="form-control form-control-sm"
                       value="{{ old('destination_url') }}" placeholder="https://…">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100">Add link</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(btn.dataset.copy).then(function () {
            var was = btn.textContent; btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = was; }, 1500);
        });
    });
});
</script>
@endsection
