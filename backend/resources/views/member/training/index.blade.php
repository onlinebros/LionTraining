@extends('layouts.member')

@section('title', 'Training')
@section('page-title', 'Training')

@section('breadcrumb')
    <li class="breadcrumb-item active">Training</li>
@endsection

@section('content')

@if(!\App\Support\TrainingAccess::isOpenToMembers())
{{-- Only an admin ever renders this: the middleware 404s everybody else. --}}
<div class="alert d-flex align-items-center gap-3 mb-4"
     style="background:var(--q3-gold-tint);border:1px solid var(--q3-border-gold);color:var(--q3-text);">
    <i data-feather="eye-off" style="width:20px;height:20px;color:var(--q3-gold-high);flex-shrink:0;"></i>
    <div class="flex-grow-1">
        <strong>Admin preview.</strong>
        The training library is hidden from members — these pages return “not found” for anyone but an
        administrator, and no video or worksheet is served. Members see no Training link at all.
        <div class="small text-muted mt-1">
            Release it from <a href="{{ route('admin.settings.index') }}">Admin → Settings</a> when it is ready.
        </div>
    </div>
</div>
@endif

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
    @php
        // A module on the drip is shown, not hidden: a member should be able to
        // see what the program holds and when their next part opens. Clicking
        // through would only bounce them back with a flash message, so the
        // locked card says the date instead of offering a dead button.
        $roleLocked = $cat->requiredRole && (!$user->role || $user->role->level < $cat->requiredRole->level);
        $timeLocked = !$roleLocked && $cat->isTimeLocked($user);
        $locked     = $roleLocked || $timeLocked;
        $availAt    = $timeLocked ? $cat->availableAtForUser($user) : null;
    @endphp
    <div class="col-sm-6 col-xl-4">
        <div class="card mb-3 h-100" style="{{ $locked ? 'opacity:.85;' : '' }}">
            @if($cat->thumbnail)
                <img src="{{ Storage::url($cat->thumbnail) }}" class="card-img-top"
                     style="height:140px;object-fit:cover;{{ $locked ? 'filter:grayscale(45%);' : '' }}">
            @endif
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-1">
                    <h6 class="fw-bold mb-0">
                        @if($locked)<span class="me-1">{{ $roleLocked ? '🔒' : '🕐' }}</span>@endif
                        {{ $cat->name }}
                    </h6>
                    @if($cat->requiredRole)
                        <span class="badge badge-light-warning ms-2 flex-shrink-0">{{ $cat->requiredRole->display_name }}</span>
                    @endif
                </div>
                @if($cat->description)
                    <p class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit($cat->description, 100) }}</p>
                @endif

                @if($timeLocked)
                    <p class="small mb-2" style="color:var(--q3-gold-high);">
                        @if($availAt)
                            Opens {{ $availAt->format('M j, Y') }}
                            <span class="text-muted">({{ now()->diffInDays($availAt) }} days)</span>
                        @else
                            Opens once your Training Program billing begins
                        @endif
                    </p>
                @elseif($roleLocked)
                    <p class="small text-muted mb-2">Requires {{ $cat->requiredRole->display_name }}</p>
                @endif

                <div class="d-flex align-items-center gap-2 mt-auto">
                    <span class="text-muted small">{{ $cat->lessons->count() }} lessons</span>
                    @if($cat->children->isNotEmpty())
                        <span class="text-muted small">· {{ $cat->children->count() }} sub-categories</span>
                    @endif
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                @if($locked)
                    <button class="btn btn-outline-secondary btn-sm w-100" disabled>
                        {{ $roleLocked ? 'Locked' : 'Not Yet Available' }}
                    </button>
                @else
                    <a href="{{ route('member.training.category', $cat->slug) }}"
                       class="btn btn-primary btn-sm w-100">
                        Browse Category <i data-feather="arrow-right" style="width:13px;height:13px;"></i>
                    </a>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

@endif
@endsection
