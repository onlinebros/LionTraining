@extends('layouts.admin')

@section('title', 'New Lesson')
@section('page-title', 'New Training Lesson')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.training.lessons.index') }}">Lessons</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Lesson Details</h5></div>
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('admin.training.lessons.store') }}" enctype="multipart/form-data">
                    @csrf
                    @include('admin.training.lessons._form', ['lesson' => null, 'selected' => $selected])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create & Add Content</button>
                        <a href="{{ route('admin.training.lessons.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
