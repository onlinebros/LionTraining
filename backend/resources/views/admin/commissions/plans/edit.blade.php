@extends('layouts.admin')

@section('title', 'Edit Commission Plan')
@section('page-title', 'Edit Commission Plan')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.commission-plans.index') }}">Commission Plans</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Edit: {{ $commissionPlan->name }}</h5></div>
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form action="{{ route('admin.commission-plans.update', $commissionPlan) }}" method="POST">
                    @csrf @method('PUT')
                    @include('admin.commissions.plans._form', ['plan' => $commissionPlan])
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('admin.commission-plans.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
