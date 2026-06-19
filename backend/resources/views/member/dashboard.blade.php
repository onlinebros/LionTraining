@extends('layouts.member')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@php
    $referralUrl = url('/join/' . $user->referral_code);
    $roleColors  = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger'];
@endphp

<div class="row g-3">

    {{-- ── Stat cards ──────────────────────────────────────── --}}
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#f0eeff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="users" style="width:22px;height:22px;color:#7366ff;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">{{ $user->sponsors->count() }}</div>
                    <div class="text-muted small">My Sponsors</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#edfceb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="award" style="width:22px;height:22px;color:#54ba4a;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">{{ $user->sponsees->count() }}</div>
                    <div class="text-muted small">Members I Sponsor</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#fff8e6;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="clock" style="width:22px;height:22px;color:#ffb829;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">{{ $user->sponsees->where('pivot.status','pending')->count() }}</div>
                    <div class="text-muted small">Pending Invites</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card mb-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:48px;height:48px;border-radius:12px;background:#fff0ef;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i data-feather="share-2" style="width:22px;height:22px;color:#fc564a;"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold lh-1">{{ $user->sponsees->count() }}</div>
                    <div class="text-muted small">Total Referrals</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Referral link card ───────────────────────────────── --}}
    <div class="col-12">
        <div class="card mb-0" style="background: linear-gradient(135deg, var(--theme-default) 0%, #563dd9 100%); color:#fff;">
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <h5 class="mb-1" style="color:#fff;">Welcome back, {{ $user->name }}!</h5>
                        <p class="mb-2" style="opacity:.8;font-size:.88rem;">
                            Share your referral link to invite members and grow your network.
                        </p>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <code style="background:rgba(255,255,255,.15);padding:6px 12px;border-radius:6px;font-size:.82rem;color:#fff;word-break:break-all;">
                                {{ $referralUrl }}
                            </code>
                            <button class="btn btn-sm"
                                    style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);"
                                    onclick="navigator.clipboard.writeText('{{ $referralUrl }}').then(()=>{this.innerHTML='<i data-feather=\'check\' style=\'width:13px;height:13px;\'></i> Copied!';feather.replace();setTimeout(()=>{this.innerHTML='<i data-feather=\'copy\' style=\'width:13px;height:13px;\'></i> Copy';feather.replace();},2000)})">
                                <i data-feather="copy" style="width:13px;height:13px;"></i> Copy
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        @if($user->role)
                        <span class="badge" style="background:rgba(255,255,255,.2);color:#fff;font-size:.78rem;padding:6px 14px;">
                            {{ $user->role->display_name }}
                        </span>
                        @endif
                        @if($user->isFreeMember())
                        <div class="mt-2">
                            <a href="#upgrade-modal" class="btn btn-sm btn-light" data-bs-toggle="modal">
                                ⭐ Upgrade to Paid
                            </a>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── My Sponsors ──────────────────────────────────────── --}}
    <div class="col-xl-6">
        <div class="card mb-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">My Sponsors</h6>
                <a href="{{ route('member.network', ['tab'=>'sponsors']) }}" class="btn btn-sm btn-outline-primary py-0">View All</a>
            </div>
            <div class="card-body">
                @forelse($user->sponsors->take(5) as $sponsor)
                    <div class="d-flex align-items-center mb-3 {{ !$loop->last ? 'pb-3 border-bottom' : '' }}">
                        <div style="width:40px;height:40px;border-radius:50%;background:var(--theme-default);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.9rem;">
                            {{ strtoupper(substr($sponsor->name, 0, 1)) }}
                        </div>
                        <div class="ms-3 flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $sponsor->name }}</div>
                            <small class="text-muted text-truncate d-block">{{ $sponsor->email }}</small>
                        </div>
                        <span class="badge badge-light-{{ $sponsor->pivot->status === 'active' ? 'success' : 'warning' }} ms-2 flex-shrink-0">
                            {{ ucfirst($sponsor->pivot->status) }}
                        </span>
                    </div>
                @empty
                    <div class="text-center py-4">
                        <i data-feather="users" style="width:36px;height:36px;opacity:.25;" class="mb-2"></i>
                        <p class="f-light small mb-0">No sponsors yet.</p>
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
                    <a href="{{ route('member.network', ['tab'=>'members']) }}" class="btn btn-sm btn-outline-primary py-0">View All</a>
                </div>
            </div>
            <div class="card-body">
                @forelse($user->sponsees->take(5) as $sponsee)
                    <div class="d-flex align-items-center mb-3 {{ !$loop->last ? 'pb-3 border-bottom' : '' }}">
                        <div style="width:40px;height:40px;border-radius:50%;background:#54ba4a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.9rem;">
                            {{ strtoupper(substr($sponsee->name, 0, 1)) }}
                        </div>
                        <div class="ms-3 flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $sponsee->name }}</div>
                            <small class="text-muted text-truncate d-block">{{ $sponsee->email }}</small>
                        </div>
                        <span class="badge badge-light-{{ $sponsee->pivot->status === 'active' ? 'success' : 'warning' }} ms-2 flex-shrink-0">
                            {{ ucfirst($sponsee->pivot->status) }}
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
