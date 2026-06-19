@extends('layouts.member')

@section('title', 'Training')
@section('page-title', 'Training')

@section('breadcrumb')
    <li class="breadcrumb-item active">Training</li>
@endsection

@section('content')

@if($categories->isEmpty())
<div class="row justify-content-center">
    <div class="col-lg-6 text-center py-5">
        <div style="font-size:3rem;margin-bottom:1rem;">📚</div>
        <h4 class="fw-bold mb-2">Training Coming Soon</h4>
        <p class="text-muted">Content is being built out. Check back soon.</p>
    </div>
</div>
@else

{{-- Featured lessons --}}
@if($featured->isNotEmpty())
<div class="row mb-4">
    <div class="col-12">
        <h5 class="fw-bold mb-3">Featured</h5>
    </div>
    @foreach($featured as $lesson)
    <div class="col-sm-6 col-xl-4">
        @include('member.training._lesson-card', ['lesson' => $lesson, 'user' => $user])
    </div>
    @endforeach
</div>
@endif

{{-- Category tree --}}
<div class="row">
    <div class="col-12">
        <h5 class="fw-bold mb-3">Browse by Category</h5>
    </div>
    @foreach($categories as $cat)
    <div class="col-sm-6 col-xl-4">
        <div class="card mb-3 h-100">
            @if($cat->thumbnail)
                <img src="{{ Storage::url($cat->thumbnail) }}" class="card-img-top"
                     style="height:140px;object-fit:cover;">
            @endif
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-1">
                    <h6 class="fw-bold mb-0">{{ $cat->name }}</h6>
                    @if($cat->requiredRole)
                        <span class="badge badge-light-warning ms-2 flex-shrink-0">{{ $cat->requiredRole->display_name }}</span>
                    @endif
                </div>
                @if($cat->description)
                    <p class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit($cat->description, 100) }}</p>
                @endif
                <div class="d-flex align-items-center gap-2 mt-auto">
                    <span class="text-muted small">{{ $cat->lessons->count() }} lessons</span>
                    @if($cat->children->isNotEmpty())
                        <span class="text-muted small">· {{ $cat->children->count() }} sub-categories</span>
                    @endif
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <a href="{{ route('member.training.category', $cat->slug) }}"
                   class="btn btn-primary btn-sm w-100">
                    Browse Category <i data-feather="arrow-right" style="width:13px;height:13px;"></i>
                </a>
            </div>
        </div>
    </div>
    @endforeach
</div>

@endif
@endsection
