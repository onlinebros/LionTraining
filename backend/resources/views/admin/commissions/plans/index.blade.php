@extends('layouts.admin')

@section('title', 'Commission Plans')
@section('page-title', 'Commission Plans')

@section('breadcrumb')
    <li class="breadcrumb-item active">Commission Plans</li>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Plans</h5>
                <a href="{{ route('admin.commission-plans.create') }}" class="btn btn-primary btn-sm">
                    <i data-feather="plus" data-width="14" data-height="14"></i> New Plan
                </a>
            </div>
            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger m-3">{{ session('error') }}</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Rate / Amount</th>
                                <th>Clawback Window</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($plans as $plan)
                            <tr>
                                <td>
                                    <strong>{{ $plan->name }}</strong>
                                    @if($plan->description)
                                        <br><small class="text-muted">{{ Str::limit($plan->description, 60) }}</small>
                                    @endif
                                </td>
                                <td><span class="badge bg-secondary">{{ $plan->type_label }}</span></td>
                                <td><code>{{ $plan->rate_summary }}</code></td>
                                <td>
                                    @if($plan->clawback_window_days)
                                        {{ $plan->clawback_window_days }} days
                                    @else
                                        <span class="text-muted">Manual only</span>
                                    @endif
                                </td>
                                <td>
                                    @if($plan->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.commission-plans.edit', $plan) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form action="{{ route('admin.commission-plans.destroy', $plan) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete this plan?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">No commission plans yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
