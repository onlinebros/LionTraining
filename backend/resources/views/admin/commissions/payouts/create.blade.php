@extends('layouts.admin')

@section('title', 'Create Payout Batch')
@section('page-title', 'Create Payout Batch')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.commission-payouts.index') }}">Commission Payouts</a></li>
    <li class="breadcrumb-item active">New Payout</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Create Payout Batch</h5></div>
            <div class="card-body">
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <p class="text-muted small">
                    This will group all unpaid, outstanding ledger entries for the selected earner (within the optional period) into a single payout batch.
                </p>
                <form action="{{ route('admin.commission-payouts.store') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Earner <span class="text-danger">*</span></label>
                        <select name="earner_id" class="form-select" required>
                            <option value="">Select user…</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ old('earner_id') == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Period Start</label>
                            <input type="date" name="period_start" class="form-control" value="{{ old('period_start') }}">
                            <div class="form-text">Leave blank to include all unpaid entries.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Period End</label>
                            <input type="date" name="period_end" class="form-control" value="{{ old('period_end') }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary">Create Payout</button>
                        <a href="{{ route('admin.commission-payouts.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
