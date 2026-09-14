@extends('layouts.admin')

@section('title', 'Error Logs')
@section('page-title', 'Error Logs')

@section('breadcrumb')
    <li class="breadcrumb-item active">Error Logs</li>
@endsection

@push('styles')
<style>
    .status-badge-new          { background: var(--q3-danger-tint);  color: #d1766e; border: 1px solid rgba(180,72,63,.32); }
    .status-badge-acknowledged { background: var(--q3-warning-tint); color: #dcb262; border: 1px solid rgba(201,154,62,.3); }
    .status-badge-resolved     { background: var(--q3-success-tint); color: #6ec49b; border: 1px solid rgba(62,158,110,.28); }
    .error-message             { max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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

    {{-- Stat cards --}}
    <div class="row mb-4">
        @foreach(['new' => ['danger','New / Unread'], 'acknowledged' => ['warning','Acknowledged'], 'resolved' => ['success','Resolved'], 'all' => ['primary','Total']] as $key => [$color, $label])
        <div class="col-xl-3 col-sm-6">
            <a href="{{ route('admin.error-logs.index', ['status' => $key]) }}" class="text-decoration-none">
                <div class="card small-widget mb-sm-0 {{ $status === $key ? 'border border-' . $color : '' }}">
                    <div class="card-body {{ $color }}">
                        <span class="f-light">{{ $label }}</span>
                        <div class="d-flex align-items-end gap-1 mt-3">
                            <h4>{{ $counts[$key] }}</h4>
                        </div>
                        <div class="bg-gradient">
                            <i data-feather="{{ $key === 'new' ? 'alert-circle' : ($key === 'acknowledged' ? 'eye' : ($key === 'resolved' ? 'check-circle' : 'list')) }}"
                               class="stroke-icon"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">
                @if($status === 'all') All Errors
                @elseif($status === 'new') New Errors
                @elseif($status === 'acknowledged') Acknowledged
                @else Resolved
                @endif
            </h5>
            <div class="d-flex gap-2">
                @foreach(['new' => 'New', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label)
                    <a href="{{ route('admin.error-logs.index', ['status' => $key]) }}"
                       class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-secondary' }}">
                        {{ $label }}
                        @if($counts[$key] > 0 && $key !== 'all')
                            <span class="badge ms-1"
                                  style="background:rgba(0,0,0,.18);color:inherit;">{{ $counts[$key] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            @if($logs->isEmpty())
                <div class="text-center py-5">
                    <i data-feather="check-circle" style="width:48px;height:48px;color:var(--q3-success);"></i>
                    <p class="mt-3 f-light">No errors in this category.</p>
                </div>
            @else
            <form id="bulk-form" method="POST" action="{{ route('admin.error-logs.bulk-resolve') }}">
                @csrf
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="36">
                                    <input type="checkbox" id="select-all" class="form-check-input">
                                </th>
                                <th>#</th>
                                <th>Message</th>
                                <th>File</th>
                                <th>URL</th>
                                <th>Status</th>
                                <th>When</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                            <tr>
                                <td>
                                    <input type="checkbox" name="ids[]" value="{{ $log->id }}" class="form-check-input row-check">
                                </td>
                                <td class="text-muted small">{{ $log->id }}</td>
                                <td>
                                    <a href="{{ route('admin.error-logs.show', $log) }}" class="fw-semibold text-dark text-decoration-none">
                                        <span class="error-message d-block" title="{{ $log->message }}">{{ $log->message }}</span>
                                    </a>
                                    @if($log->context['exception'] ?? null)
                                        <small class="text-muted">{{ class_basename($log->context['exception']) }}</small>
                                    @endif
                                </td>
                                <td class="small text-muted">
                                    @if($log->file)
                                        {{ basename($log->file) }}@if($log->line):{{ $log->line }}@endif
                                    @else —
                                    @endif
                                </td>
                                <td class="small text-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    {{ $log->url ?? '—' }}
                                </td>
                                <td>
                                    <span class="badge status-badge-{{ $log->status }} px-2 py-1">
                                        {{ ucfirst($log->status) }}
                                    </span>
                                </td>
                                <td class="small text-muted text-nowrap">
                                    {{ $log->created_at->diffForHumans() }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.error-logs.show', $log) }}"
                                       class="btn btn-info btn-sm">
                                        <i data-feather="eye" data-width="14" data-height="14"></i> View
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($status !== 'resolved')
                <div class="d-flex align-items-center gap-2 p-3 border-top">
                    <span class="small text-muted" id="selected-count">0 selected</span>
                    <button type="submit" class="btn btn-sm btn-success" id="bulk-btn" disabled>
                        Mark Selected as Resolved
                    </button>
                </div>
                @endif
            </form>

            <div class="p-3">
                {{ $logs->links() }}
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const selectAll = document.getElementById('select-all');
const checks    = () => document.querySelectorAll('.row-check');
const countEl   = document.getElementById('selected-count');
const bulkBtn   = document.getElementById('bulk-btn');

function updateBulk() {
    const n = document.querySelectorAll('.row-check:checked').length;
    if (countEl) countEl.textContent = n + ' selected';
    if (bulkBtn) bulkBtn.disabled = n === 0;
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        checks().forEach(c => c.checked = this.checked);
        updateBulk();
    });
}

document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulk));
</script>
@endpush
