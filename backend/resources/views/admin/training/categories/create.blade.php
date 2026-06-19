@extends('layouts.admin')

@section('title', 'New Category')
@section('page-title', 'New Training Category')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.training.categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Category Details</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.training.categories.store') }}" enctype="multipart/form-data">
                    @csrf
                    @include('admin.training.categories._form', ['category' => null])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create Category</button>
                        <a href="{{ route('admin.training.categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
