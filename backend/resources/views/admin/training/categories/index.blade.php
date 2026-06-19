@extends('layouts.admin')

@section('title', 'Training Categories')
@section('page-title', 'Training Categories')

@section('breadcrumb')
    <li class="breadcrumb-item">Training</li>
    <li class="breadcrumb-item active">Categories</li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Categories</h5>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.training.lessons.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i data-feather="list" style="width:14px;height:14px;"></i> All Lessons
                    </a>
                    <a href="{{ route('admin.training.categories.create') }}" class="btn btn-primary btn-sm">
                        <i data-feather="plus" style="width:14px;height:14px;"></i> New Category
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3 mb-0">{{ session('success') }}</div>
                @endif

                @if($roots->isEmpty())
                    <p class="text-muted p-4 mb-0">No categories yet. <a href="{{ route('admin.training.categories.create') }}">Create one</a>.</p>
                @else
                    <div class="p-3">
                        @include('admin.training.categories._tree', ['items' => $roots, 'depth' => 0])
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
