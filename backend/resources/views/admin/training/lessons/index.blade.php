@extends('layouts.admin')

@section('title', 'Training Lessons')
@section('page-title', 'Training Lessons')

@section('breadcrumb')
    <li class="breadcrumb-item">Training</li>
    <li class="breadcrumb-item active">Lessons</li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Lessons</h5>
                <div class="d-flex gap-2 align-items-center">
                    <select class="form-select form-select-sm" style="width:auto;"
                            onchange="window.location='{{ route('admin.training.lessons.index') }}?category='+this.value">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>
                                {{ $cat->name }}
                            </option>
                        @endforeach
                    </select>
                    <a href="{{ route('admin.training.categories.index') }}" class="btn btn-secondary btn-sm">
                        <i data-feather="folder" data-width="14" data-height="14"></i> Categories
                    </a>
                    <a href="{{ route('admin.training.lessons.create') }}" class="btn btn-primary btn-sm">
                        <i data-feather="plus" data-width="14" data-height="14"></i> New Lesson
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3 mb-0">{{ session('success') }}</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Role</th>
                                <th>Blocks</th>
                                <th>Status</th>
                                <th>Order</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lessons as $lesson)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $lesson->title }}</div>
                                    <small class="text-muted">/l/{{ $lesson->slug }}</small>
                                </td>
                                <td class="text-muted small">{{ $lesson->category?->name }}</td>
                                <td>
                                    @if($lesson->requiredRole)
                                        <span class="badge badge-light-warning">{{ $lesson->requiredRole->display_name }}</span>
                                    @elseif($lesson->category?->required_role_id)
                                        <span class="badge badge-light-secondary" title="Inherited from category">
                                            {{ $lesson->category->requiredRole?->display_name }} <i>(cat)</i>
                                        </span>
                                    @else
                                        <span class="text-muted small">All</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $lesson->allContentBlocks()->count() }}</td>
                                <td>
                                    @if($lesson->is_published)
                                        <span class="badge badge-light-success">Published</span>
                                    @else
                                        <span class="badge badge-light-danger">Draft</span>
                                    @endif
                                    @if($lesson->is_featured)
                                        <span class="badge badge-light-primary ms-1">Featured</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $lesson->sort_order }}</td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="{{ route('admin.training.lessons.edit', $lesson) }}"
                                           class="btn btn-warning btn-sm">
                                            <i data-feather="edit-2" data-width="14" data-height="14"></i> Edit
                                        </a>
                                        <form method="POST" action="{{ route('admin.training.lessons.destroy', $lesson) }}"
                                              class="d-inline" onsubmit="return confirm('Delete this lesson?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-danger btn-sm">
                                                <i data-feather="trash-2" data-width="14" data-height="14"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-muted text-center py-4">No lessons found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($lessons->hasPages())
                    <div class="p-3">{{ $lessons->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
