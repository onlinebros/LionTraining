@extends('layouts.member')

@section('title', 'My Network')
@section('page-title', 'My Network')

@section('breadcrumb')
    <li class="breadcrumb-item active">My Network</li>
@endsection

@section('content')
@php
    $sponsors = $user->sponsors;
    $sponsees = $user->sponsees;
@endphp

{{-- ── Tabs ──────────────────────────────────────────────── --}}
<ul class="nav nav-tabs mb-4" id="networkTabs">
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'members' ? 'active' : '' }}"
           href="{{ route('member.network', ['tab'=>'members']) }}">
            <i data-feather="award" style="width:15px;height:15px;" class="me-1"></i>
            Members I Sponsor
            @if($sponsees->count())
                <span class="badge bg-primary ms-1">{{ $sponsees->count() }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'sponsors' ? 'active' : '' }}"
           href="{{ route('member.network', ['tab'=>'sponsors']) }}">
            <i data-feather="users" style="width:15px;height:15px;" class="me-1"></i>
            My Sponsors
            @if($sponsors->count())
                <span class="badge bg-primary ms-1">{{ $sponsors->count() }}</span>
            @endif
        </a>
    </li>
</ul>

{{-- ── Members tab ─────────────────────────────────────── --}}
@if($tab === 'members')
<div class="row g-3">
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Members I Sponsor ({{ $sponsees->count() }})</h6>
                <a href="{{ route('member.referrals') }}" class="btn btn-primary btn-sm">
                    <i data-feather="share-2" style="width:13px;height:13px;" class="me-1"></i>Invite Member
                </a>
            </div>

            @if($sponsees->isEmpty())
                <div class="card-body text-center py-5">
                    <i data-feather="award" style="width:48px;height:48px;opacity:.2;" class="mb-3"></i>
                    <h6 class="f-light">You haven't sponsored any members yet.</h6>
                    <p class="text-muted small mb-3">Share your referral link to invite members to join under your sponsorship.</p>
                    <a href="{{ route('member.referrals') }}" class="btn btn-primary">
                        <i data-feather="share-2" style="width:14px;height:14px;" class="me-1"></i>Get My Referral Link
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Member</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sponsees as $sponsee)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width:36px;height:36px;border-radius:50%;background:#54ba4a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.85rem;">
                                            {{ strtoupper(substr($sponsee->name, 0, 1)) }}
                                        </div>
                                        <div class="fw-semibold">{{ $sponsee->name }}</div>
                                    </div>
                                </td>
                                <td class="text-muted small">{{ $sponsee->email }}</td>
                                <td>
                                    <span class="badge badge-light-{{ $sponsee->pivot->status === 'active' ? 'success' : 'warning' }}">
                                        {{ ucfirst($sponsee->pivot->status) }}
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    {{ $sponsee->pivot->created_at ? \Carbon\Carbon::parse($sponsee->pivot->created_at)->format('d M Y') : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ── Sponsors tab ─────────────────────────────────────── --}}
@if($tab === 'sponsors')
<div class="row g-3">
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">My Sponsors ({{ $sponsors->count() }})</h6>
            </div>

            @if($sponsors->isEmpty())
                <div class="card-body text-center py-5">
                    <i data-feather="users" style="width:48px;height:48px;opacity:.2;" class="mb-3"></i>
                    <h6 class="f-light">No sponsors yet.</h6>
                    <p class="text-muted small">When someone invites you using their referral link, they will appear here.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Sponsor</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Since</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sponsors as $sponsor)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width:36px;height:36px;border-radius:50%;background:var(--theme-default);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.85rem;">
                                            {{ strtoupper(substr($sponsor->name, 0, 1)) }}
                                        </div>
                                        <div class="fw-semibold">{{ $sponsor->name }}</div>
                                    </div>
                                </td>
                                <td class="text-muted small">{{ $sponsor->email }}</td>
                                <td>
                                    <span class="badge badge-light-{{ $sponsor->pivot->status === 'active' ? 'success' : 'warning' }}">
                                        {{ ucfirst($sponsor->pivot->status) }}
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    {{ $sponsor->pivot->created_at ? \Carbon\Carbon::parse($sponsor->pivot->created_at)->format('d M Y') : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endif

@endsection
