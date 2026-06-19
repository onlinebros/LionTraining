@extends('layouts.admin')

@section('title', 'Error #' . $errorLog->id)
@section('page-title', 'Error Detail')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.error-logs.index') }}">Error Logs</a></li>
    <li class="breadcrumb-item active">#{{ $errorLog->id }}</li>
@endsection

@push('styles')
<style>
    .trace-box { background:#1a1a2e; color:#e2e8f0; font-size:.78rem; line-height:1.6; border-radius:8px; padding:18px; overflow-x:auto; white-space:pre; max-height:420px; overflow-y:auto; }
    .meta-row th { width:160px; font-weight:600; color:#52526c; vertical-align:top; padding:8px 12px; }
    .meta-row td { padding:8px 12px; }
    .status-new          { background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7; }
    .status-acknowledged { background:#fffbeb; color:#d69e2e; border:1px solid #fefcbf; }
    .status-resolved     { background:#f0fff4; color:#38a169; border:1px solid #c6f6d5; }
</style>
@endpush

@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">

        {{-- Main error detail --}}
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Error #{{ $errorLog->id }}</h5>
                    <span class="badge status-{{ $errorLog->status }} px-2 py-1">{{ ucfirst($errorLog->status) }}</span>
                </div>
                <div class="card-body">

                    <div class="alert alert-danger py-2 fw-semibold mb-4" style="word-break:break-all;">
                        {{ $errorLog->message }}
                    </div>

                    <table class="table table-borderless meta-row">
                        <tr>
                            <th>Exception</th>
                            <td>{{ $errorLog->context['exception'] ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>File</th>
                            <td><code>{{ $errorLog->file ?? '—' }}{{ $errorLog->line ? ':' . $errorLog->line : '' }}</code></td>
                        </tr>
                        <tr>
                            <th>URL</th>
                            <td>
                                @if($errorLog->url)
                                    <span class="badge bg-secondary me-1">{{ $errorLog->method }}</span>
                                    <code>{{ $errorLog->url }}</code>
                                @else —
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>User</th>
                            <td>
                                @if($errorLog->user)
                                    <a href="{{ route('admin.users.show', $errorLog->user) }}">{{ $errorLog->user->name }}</a>
                                    <small class="text-muted">(#{{ $errorLog->user_id }})</small>
                                @else
                                    <span class="text-muted">Guest</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Occurred</th>
                            <td>{{ $errorLog->created_at->format('Y-m-d H:i:s') }} ({{ $errorLog->created_at->diffForHumans() }})</td>
                        </tr>
                        @if($errorLog->resolved_at)
                        <tr>
                            <th>Resolved</th>
                            <td>{{ $errorLog->resolved_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                        @endif
                    </table>

                    @if($errorLog->trace)
                    <h6 class="mt-4 mb-2 fw-semibold">Stack Trace</h6>
                    <div class="trace-box">{{ $errorLog->trace }}</div>
                    @endif

                </div>
            </div>
        </div>

        {{-- Actions panel --}}
        <div class="col-xl-4">

            {{-- Update status --}}
            <div class="card">
                <div class="card-header"><h6 class="mb-0">Update Status</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.error-logs.update', $errorLog) }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                @foreach(['new' => 'New', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'] as $val => $label)
                                    <option value="{{ $val }}" {{ $errorLog->status === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Resolution Notes</label>
                            <textarea name="resolution_notes" class="form-control" rows="5"
                                      placeholder="Describe what was done to fix this error...">{{ old('resolution_notes', $errorLog->resolution_notes) }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Save</button>
                    </form>
                </div>
            </div>

            {{-- Delete --}}
            <div class="card border-0">
                <div class="card-body pt-0">
                    <form method="POST" action="{{ route('admin.error-logs.destroy', $errorLog) }}"
                          onsubmit="return confirm('Delete this error log permanently?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger w-100 btn-sm">
                            <i data-feather="trash-2" style="width:13px;height:13px;"></i> Delete Log
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
