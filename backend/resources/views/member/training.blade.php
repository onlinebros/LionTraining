@extends('layouts.member')

@section('title', 'Training')
@section('page-title', 'Training')

@section('breadcrumb')
    <li class="breadcrumb-item active">Training</li>
@endsection

@section('content')

@if(!$user->isPaidOrAbove())
{{-- ── Free member gate ─────────────────────────────────── --}}
<div class="row justify-content-center">
    <div class="col-lg-6 text-center py-5">
        <div style="font-size:3.5rem;margin-bottom:1rem;">🔒</div>
        <h4 class="fw-bold mb-2">Training is a Paid Feature</h4>
        <p class="text-muted mb-4">
            Upgrade your account to access training plans, workout programs, performance tracking, and more.
        </p>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#upgrade-modal">
            ⭐ Upgrade to Paid
        </button>
        <p class="text-muted small mt-3">Contact your sponsor or an administrator to upgrade.</p>
    </div>
</div>
@else
{{-- ── Paid member training area ───────────────────────── --}}
<div class="row g-3">

    <div class="col-12">
        <div class="alert alert-info d-flex align-items-center gap-2 mb-0">
            <i data-feather="info" style="width:18px;height:18px;flex-shrink:0;"></i>
            <span>Training content is coming soon. This section is being built out.</span>
        </div>
    </div>

    @php
    $modules = [
        ['icon'=>'activity',     'title'=>'My Workout Plans',     'desc'=>'Personalized training programs tailored to your goals.', 'status'=>'coming_soon'],
        ['icon'=>'bar-chart-2',  'title'=>'Performance Tracking',  'desc'=>'Track your progress over time with detailed analytics.', 'status'=>'coming_soon'],
        ['icon'=>'video',        'title'=>'Video Library',         'desc'=>'Access a library of training videos and tutorials.', 'status'=>'coming_soon'],
        ['icon'=>'calendar',     'title'=>'Schedule',              'desc'=>'Plan and schedule your training sessions.', 'status'=>'coming_soon'],
        ['icon'=>'target',       'title'=>'Goals',                 'desc'=>'Set and track your fitness and performance goals.', 'status'=>'coming_soon'],
        ['icon'=>'message-square','title'=>'Coaching Messages',    'desc'=>'Communicate with your sponsor and coaching team.', 'status'=>'coming_soon'],
    ];
    @endphp

    @foreach($modules as $module)
    <div class="col-sm-6 col-xl-4">
        <div class="card mb-0 h-100" style="opacity:.7;">
            <div class="card-body">
                <div style="width:44px;height:44px;border-radius:10px;background:var(--light-bg,#eef1f6);display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i data-feather="{{ $module['icon'] }}" style="width:20px;height:20px;color:var(--theme-default);"></i>
                </div>
                <h6 class="fw-bold mb-1">{{ $module['title'] }}</h6>
                <p class="text-muted small mb-3">{{ $module['desc'] }}</p>
                <span class="badge badge-light-warning">Coming Soon</span>
            </div>
        </div>
    </div>
    @endforeach

</div>
@endif

@endsection
