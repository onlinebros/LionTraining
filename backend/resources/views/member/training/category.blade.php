@extends('layouts.member')

@section('title', $category->name . ' — Training')
@section('page-title', $category->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('member.training') }}">Training</a></li>
    @foreach($breadcrumb as $crumb)
        @if(!$loop->last)
            <li class="breadcrumb-item">
                <a href="{{ route('member.training.category', $crumb->slug) }}">{{ $crumb->name }}</a>
            </li>
        @else
            <li class="breadcrumb-item active">{{ $crumb->name }}</li>
        @endif
    @endforeach
@endsection

@section('content')

@if($category->description)
<div class="alert alert-light mb-4">{{ $category->description }}</div>
@endif

{{-- Sub-categories --}}
@if($category->children->isNotEmpty())
<h5 class="fw-bold mb-3">Sub-Categories</h5>
<div class="row mb-4">
    @foreach($category->children->where('is_active', true) as $child)
    @php
        $child->load('requiredRole');
        $childRoleLocked = $child->requiredRole && (!$user->role || $user->role->level < $child->requiredRole->level);
        $childTimeLocked = !$childRoleLocked && $child->isTimeLocked($user);
        $childLocked     = $childRoleLocked || $childTimeLocked;
        $childAvailAt    = $childTimeLocked ? $child->availableAtForUser($user) : null;
    @endphp
    <div class="col-sm-6 col-xl-4">
        <div class="card mb-3" style="{{ $childLocked ? 'opacity:.8;' : '' }}">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:40px;height:40px;background:var(--light-bg,#eef1f6);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="folder" style="width:18px;height:18px;color:var(--theme-default);"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">{{ $child->name }}</div>
                    <small class="text-muted">{{ $child->lessons->count() }} lessons</small>
                    @if($childTimeLocked && $childAvailAt)
                        <br><small style="color:var(--q3-info);">Available {{ $childAvailAt->format('M j, Y') }}</small>
                    @elseif($childTimeLocked)
                        <br><small class="text-muted">Start date not yet set</small>
                    @endif
                </div>
                @if($childRoleLocked)
                    <span title="Requires {{ $child->requiredRole->display_name }}">🔒</span>
                @elseif($childTimeLocked)
                    <span title="Not yet available">🕐</span>
                @else
                    <a href="{{ route('member.training.category', $child->slug) }}"
                       class="btn btn-outline-primary btn-sm">Go</a>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

{{-- Lessons --}}
@if($lessons->isNotEmpty())
<h5 class="fw-bold mb-3">Lessons</h5>
<div class="row">
    @foreach($lessons as $lesson)
    <div class="col-sm-6 col-xl-4">
        @include('member.training._lesson-card', ['lesson' => $lesson, 'user' => $user])
    </div>
    @endforeach
</div>
@elseif($category->children->isEmpty())
<div class="text-center py-5 text-muted">
    <i data-feather="inbox" style="width:40px;height:40px;opacity:.3;"></i>
    <p class="mt-2">No lessons in this category yet.</p>
</div>
@endif

@endsection
