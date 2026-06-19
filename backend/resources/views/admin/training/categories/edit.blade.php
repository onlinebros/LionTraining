@extends('layouts.admin')

@section('title', 'Edit Category')
@section('page-title', 'Edit Category')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.training.categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active">{{ $category->name }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Edit — {{ $category->name }}</h5></div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('admin.training.categories.update', $category) }}" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    @include('admin.training.categories._form', ['category' => $category])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('admin.training.categories.index') }}" class="btn btn-outline-secondary">Back</a>
                        <form method="POST" action="{{ route('admin.training.categories.destroy', $category) }}"
                              class="ms-auto" onsubmit="return confirm('Delete this category?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
