@extends('layouts.member')

@section('title', 'Referrals')
@section('page-title', 'Referrals')

@section('breadcrumb')
    <li class="breadcrumb-item active">Referrals</li>
@endsection

@section('content')
@php
    // Carries the partner's own business line, so a free clean-air partner's
    // link does not ask their prospect for a card. See User::referralJoinUrl().
    $referralUrl = $user->referralJoinUrl();
    // Their own line's marketing site, not always the company one: a
    // PlasmaGuard partner shares the clean-air site. See App\Support\Opportunity.
    $siteUrl = $user->opportunity()->partnerSiteUrl($user->referral_code);
@endphp

<div class="row g-3">

    {{-- ── Stat cards ──────────────────────────────────────── --}}
    <div class="col-sm-4">
        <div class="card mb-0">
            <div class="card-body text-center py-4">
                <div class="fs-2 fw-bold text-primary">{{ $stats['total_invited'] }}</div>
                <div class="text-muted small mt-1">Total Invited</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card mb-0">
            <div class="card-body text-center py-4">
                <div class="fs-2 fw-bold" style="color:var(--q3-success);">{{ $stats['active'] }}</div>
                <div class="text-muted small mt-1">Active</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card mb-0">
            <div class="card-body text-center py-4">
                <div class="fs-2 fw-bold" style="color:var(--q3-warning);">{{ number_format($stats['team']) }}</div>
                <div class="text-muted small mt-1">Total Team</div>
            </div>
        </div>
    </div>

    {{-- ── Referral link card ───────────────────────────────── --}}
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-body">
                <h6 class="fw-bold mb-1">Your Referral Link</h6>
                <p class="text-muted small mb-3">
                    Share this link with people you want to sponsor. When they sign up through your link, they'll be connected to you automatically as a member.
                </p>

                <div class="d-flex align-items-center gap-2 flex-wrap mb-4">
                    <input type="text" id="referralInput" class="form-control"
                           value="{{ $referralUrl }}" readonly
                           style="font-family:monospace;font-size:.85rem;max-width:500px;">
                    <button class="btn btn-primary" id="copyBtn"
                            onclick="
                                navigator.clipboard.writeText('{{ $referralUrl }}').then(() => {
                                    this.innerHTML = '<i data-feather=\'check\' style=\'width:14px;height:14px;\'></i> Copied!';
                                    feather.replace();
                                    setTimeout(() => { this.innerHTML = '<i data-feather=\'copy\' style=\'width:14px;height:14px;\'></i> Copy Link'; feather.replace(); }, 2500);
                                })">
                        <i data-feather="copy" style="width:14px;height:14px;" class="me-1"></i>Copy Link
                    </button>
                </div>

                <h6 class="fw-bold mb-1">Your Website</h6>
                <p class="text-muted small mb-3">
                    Your own copy of the company website. It shows visitors that you invited them, and its Join buttons use your referral link.
                </p>

                <div class="d-flex align-items-center gap-2 flex-wrap mb-4">
                    <input type="text" class="form-control" value="{{ $siteUrl }}" readonly
                           style="font-family:monospace;font-size:.85rem;max-width:500px;">
                    <button class="btn btn-outline-primary"
                            onclick="navigator.clipboard.writeText('{{ $siteUrl }}').then(() => { this.textContent = 'Copied!'; setTimeout(() => { this.textContent = 'Copy Website'; }, 2500); })">Copy Website</button>
                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="btn btn-link">Open</a>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="https://wa.me/?text={{ urlencode('Join Quantum 3 Solution through my referral link: ' . $referralUrl) }}"
                       target="_blank" class="btn btn-sm btn-outline-success">
                        <i data-feather="message-circle" style="width:13px;height:13px;" class="me-1"></i>WhatsApp
                    </a>
                    <a href="https://twitter.com/intent/tweet?text={{ urlencode('Join me on Quantum 3 Solution! ' . $referralUrl) }}"
                       target="_blank" class="btn btn-sm btn-outline-info">
                        <i data-feather="twitter" style="width:13px;height:13px;" class="me-1"></i>Twitter / X
                    </a>
                    <a href="mailto:?subject={{ urlencode('Join Quantum 3 Solution') }}&body={{ urlencode('I\'d like to invite you to join Quantum 3 Solution. Use my referral link: ' . $referralUrl) }}"
                       class="btn btn-sm btn-outline-secondary">
                        <i data-feather="mail" style="width:13px;height:13px;" class="me-1"></i>Email
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Invited members list ────────────────────────────── --}}
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">Members Invited</h6>
            </div>

            @if($recruits->isEmpty())
                <div class="card-body text-center py-5">
                    <i data-feather="share-2" style="width:48px;height:48px;opacity:.2;" class="mb-3"></i>
                    <h6 class="f-light">No invites sent yet.</h6>
                    <p class="text-muted small">Copy your referral link above and share it to get started.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recruits as $sponsee)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="q3-avatar q3-avatar-sm">{{ strtoupper(substr($sponsee->name, 0, 1)) }}</div>
                                        <span class="fw-semibold">{{ $sponsee->name }}</span>
                                    </div>
                                </td>
                                <td class="text-muted small">{{ $sponsee->email }}</td>
                                <td>
                                    @if($sponsee->role)
                                        <span class="badge badge-light-info small">{{ $sponsee->role->display_name }}</span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-light-{{ $sponsee->is_active ? 'success' : 'secondary' }}">
                                        {{ $sponsee->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    {{ ($sponsee->placed_at ?? $sponsee->created_at)?->format('d M Y') ?? '—' }}
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
@endsection
