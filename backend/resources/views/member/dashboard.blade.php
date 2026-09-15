@extends('layouts.member')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@php
    $referralUrl = url('/join/' . $user->referral_code);
    $roleColors  = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger'];
@endphp

<div class="row g-3">

    {{-- ── Welcome strip ────────────────────────────────────────
         Deliberately understated: name, membership status and the one
         headline metric. Not a hero banner. --}}
    <div class="col-12">
        <div class="card q3-welcome mb-0">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md-7">
                        <div class="q3-welcome-name">Welcome back, {{ $user->name }}</div>
                        <div class="q3-welcome-sub mt-1">
                            @if($user->role)
                                <span class="badge badge-light-primary me-2">{{ $user->role->display_name }}</span>
                            @endif
                            Member since {{ $user->created_at?->format('F Y') }}
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="d-flex justify-content-md-end align-items-center gap-4">
                            <div class="text-md-end">
                                <div class="q3-stat-value q3-stat-value--gold">{{ number_format($stats['team']) }}</div>
                                <div class="q3-stat-label">Total Team</div>
                            </div>
                            @if($user->isFreeMember())
                            <a href="#upgrade-modal" class="btn btn-sm btn-primary" data-bs-toggle="modal">Upgrade</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── First-100 offer, while places remain ─────────────────── --}}
    @if ($buyYours)
        <div class="col-12">
            @include('member.vendor.partials.buy-yours')
        </div>
    @endif

    {{-- ── Stat cards ──────────────────────────────────────────
         Gold is spent only on the enrolment count — the metric a partner
         acts on. The rest stay off-white so the accent keeps its weight. --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon"><i data-feather="users"></i></div>
                <div class="min-w-0">
                    <div class="q3-stat-value text-truncate">{{ $stats['sponsor']?->name ?? '—' }}</div>
                    <div class="q3-stat-label">My Sponsor</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon q3-stat-icon--gold"><i data-feather="award"></i></div>
                <div>
                    <div class="q3-stat-value q3-stat-value--gold">{{ number_format($stats['directs']) }}</div>
                    <div class="q3-stat-label">Personally Enrolled</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon"><i data-feather="clock"></i></div>
                <div>
                    <div class="q3-stat-value">{{ number_format($stats['team']) }}</div>
                    <div class="q3-stat-label">Total Team</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body q3-stat">
                <div class="q3-stat-icon"><i data-feather="share-2"></i></div>
                <div>
                    <div class="q3-stat-value">{{ $stats['depth'] }}</div>
                    <div class="q3-stat-label">My Depth</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Referral link card ───────────────────────────────── --}}
    <div class="col-12">
        <div class="card q3-card-featured mb-0">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <h6 class="mb-1">Your referral link</h6>
                        <p class="text-muted mb-3" style="font-size:.85rem;">
                            Share it to invite members and grow your network.
                        </p>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <code class="q3-mono">{{ $referralUrl }}</code>
                            <button class="btn btn-sm btn-secondary"
                                    onclick="navigator.clipboard.writeText('{{ $referralUrl }}').then(()=>{this.innerHTML='<i data-feather=\'check\' style=\'width:13px;height:13px;\'></i> Copied!';feather.replace();setTimeout(()=>{this.innerHTML='<i data-feather=\'copy\' style=\'width:13px;height:13px;\'></i> Copy';feather.replace();},2000)})">
                                <i data-feather="copy" style="width:13px;height:13px;"></i> Copy
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="{{ route('member.referrals') }}" class="btn btn-sm btn-primary">
                            <i data-feather="share-2" style="width:13px;height:13px;"></i> Share Invite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── My Sponsors ──────────────────────────────────────── --}}
    <div class="col-xl-6">
        <div class="card mb-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">My Sponsor</h6>
                <a href="{{ route('member.network') }}" class="btn btn-sm btn-outline-primary py-0">View Team</a>
            </div>
            <div class="card-body">
                @forelse($stats['sponsor'] ? [$stats['sponsor']] : [] as $sponsor)
                    <div class="d-flex align-items-center mb-3 {{ !$loop->last ? 'pb-3 border-bottom' : '' }}">
                        <div class="q3-avatar q3-avatar-lg">{{ strtoupper(substr($sponsor->name, 0, 1)) }}</div>
                        <div class="ms-3 flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $sponsor->name }}</div>
                            <small class="text-muted text-truncate d-block">{{ $sponsor->email }}</small>
                        </div>
                        <span class="badge badge-light-primary ms-2 flex-shrink-0">Sponsor</span>
                    </div>
                @empty
                    <div class="text-center py-4">
                        <i data-feather="users" style="width:36px;height:36px;opacity:.25;" class="mb-2"></i>
                        <p class="f-light small mb-0">You joined without a sponsor — you are a tree root.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Members I Sponsor ───────────────────────────────── --}}
    <div class="col-xl-6">
        <div class="card mb-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Members I Sponsor</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-primary py-0"
                            onclick="navigator.clipboard.writeText('{{ $referralUrl }}').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Share Invite',2000)})">
                        Share Invite
                    </button>
                    <a href="{{ route('member.network') }}" class="btn btn-sm btn-outline-primary py-0">View All</a>
                </div>
            </div>
            <div class="card-body">
                @forelse($user->recruits()->latest('id')->take(5)->get() as $sponsee)
                    <div class="d-flex align-items-center mb-3 {{ !$loop->last ? 'pb-3 border-bottom' : '' }}">
                        <div class="q3-avatar q3-avatar-lg">{{ strtoupper(substr($sponsee->name, 0, 1)) }}</div>
                        <div class="ms-3 flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $sponsee->name }}</div>
                            <small class="text-muted text-truncate d-block">{{ $sponsee->email }}</small>
                        </div>
                        <span class="badge badge-light-{{ $sponsee->is_active ? 'success' : 'secondary' }} ms-2 flex-shrink-0">
                            {{ $sponsee->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                @empty
                    <div class="text-center py-4">
                        <i data-feather="award" style="width:36px;height:36px;opacity:.25;" class="mb-2"></i>
                        <p class="f-light small mb-2">No members yet.</p>
                        <a href="{{ route('member.referrals') }}" class="btn btn-sm btn-primary">
                            <i data-feather="share-2" style="width:13px;height:13px;"></i> Share Your Link
                        </a>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

</div>
@endsection
