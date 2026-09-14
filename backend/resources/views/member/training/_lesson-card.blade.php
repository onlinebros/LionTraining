@php
    $roleLocked  = $lesson->isRoleLocked($user);
    $timeLocked  = !$roleLocked && $lesson->isTimeLocked($user);
    $anyLocked   = $roleLocked || $timeLocked;
    $availableAt = $timeLocked ? $lesson->availableAtForUser($user) : null;
    $roleNeeded  = $roleLocked ? \App\Models\Role::find($lesson->effectiveRoleId()) : null;
@endphp
<div class="card mb-3 h-100" style="{{ $anyLocked ? 'opacity:.8;' : '' }}">
    @if($lesson->thumbnail)
        <img src="{{ Storage::url($lesson->thumbnail) }}" class="card-img-top"
             style="height:130px;object-fit:cover;{{ $anyLocked ? 'filter:grayscale(40%)' : '' }}">
    @endif
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
            <h6 class="fw-bold mb-1">{{ $lesson->title }}</h6>
            @if($roleLocked)
                <span class="ms-2 flex-shrink-0" title="Requires {{ $roleNeeded?->display_name }}">🔒</span>
            @elseif($timeLocked)
                <span class="ms-2 flex-shrink-0" title="Not yet available">🕐</span>
            @endif
        </div>
        @if($lesson->description)
            <p class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit($lesson->description, 90) }}</p>
        @endif
        @if($roleLocked && $roleNeeded)
            <p class="small mb-0" style="color:var(--q3-warning);">Requires: {{ $roleNeeded->display_name }}</p>
        @elseif($timeLocked)
            @if($availableAt)
                <p class="small mb-0" style="color:var(--q3-info);">
                    Available {{ $availableAt->format('M j, Y') }}
                    <span class="text-muted">(in {{ now()->diffInDays($availableAt) }} days)</span>
                </p>
            @else
                <p class="small mb-0 text-muted">Available once your active start date is set.</p>
            @endif
        @endif
    </div>
    <div class="card-footer bg-transparent border-top-0 pt-0">
        @if($anyLocked)
            <button class="btn btn-outline-secondary btn-sm w-100" disabled>
                {{ $roleLocked ? '🔒 Locked' : '🕐 Not Yet Available' }}
            </button>
        @else
            <a href="{{ route('member.training.lesson', $lesson->slug) }}"
               class="btn btn-primary btn-sm w-100">
                View Lesson <i data-feather="arrow-right" style="width:13px;height:13px;"></i>
            </a>
        @endif
    </div>
</div>
