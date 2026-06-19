@extends('layouts.admin')

@section('title', 'New Commission Plan')
@section('page-title', 'New Commission Plan')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.commission-plans.index') }}">Commission Plans</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Create Commission Plan</h5></div>
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form action="{{ route('admin.commission-plans.store') }}" method="POST">
                    @csrf
                    @include('admin.commissions.plans._form', ['plan' => null])
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-primary">Create Plan</button>
                        <a href="{{ route('admin.commission-plans.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
