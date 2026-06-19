@extends('layouts.member')

@section('title', 'My Dashboard')


@section('content')
    <div class="row">

        {{-- Welcome card --}}
        <div class="col-12">
            <div class="card o-hidden">
                <div class="card-body" style="background: linear-gradient(135deg, #7366ff 0%, #563dd9 100%); color: #fff; border-radius: 8px;">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h4 class="mb-1" style="color:#fff;">Welcome back, {{ auth()->user()->name }}!</h4>
                            <p class="mb-2" style="opacity:.85;">{{ auth()->user()->email }}</p>
                            <small style="opacity:.7;">
                                Referral link:
                                <strong>{{ url('/join/' . auth()->user()->referral_code) }}</strong>
                            </small>
                        </div>
                        <div class="ms-3">
                            <button class="btn btn-light btn-sm"
                                    onclick="navigator.clipboard.writeText('{{ url('/join/' . auth()->user()->referral_code) }}'); this.textContent='Copied!'">
                                <i data-feather="copy" style="width:14px;height:14px;"></i> Copy Link
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- My Sponsors --}}
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header">
                    <h5>My Sponsors</h5>
                </div>
                <div class="card-body">
                    @forelse(auth()->user()->sponsors as $sponsor)
                        <div class="d-flex align-items-center mb-3">
                            <div style="width:38px;height:38px;border-radius:50%;background:#7366ff;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">
                                {{ strtoupper(substr($sponsor->name, 0, 1)) }}
                            </div>
                            <div class="ms-3 flex-grow-1">
                                <div class="fw-bold">{{ $sponsor->name }}</div>
                                <small class="text-muted">{{ $sponsor->email }}</small>
                            </div>
                            <span class="badge badge-light-{{ $sponsor->pivot->status === 'active' ? 'success' : 'warning' }}">
                                {{ ucfirst($sponsor->pivot->status) }}
                            </span>
                        </div>
                    @empty
                        <p class="f-light text-center py-3">You don't have any sponsors yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- My Sponsees --}}
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Members I Sponsor</h5>
                    <button class="btn btn-primary btn-sm"
                            onclick="navigator.clipboard.writeText('{{ url('/join/' . auth()->user()->referral_code) }}')">
                        <i data-feather="share-2" style="width:13px;height:13px;"></i> Share Invite
                    </button>
                </div>
                <div class="card-body">
                    @forelse(auth()->user()->sponsees as $sponsee)
                        <div class="d-flex align-items-center mb-3">
                            <div style="width:38px;height:38px;border-radius:50%;background:#54ba4a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">
                                {{ strtoupper(substr($sponsee->name, 0, 1)) }}
                            </div>
                            <div class="ms-3 flex-grow-1">
                                <div class="fw-bold">{{ $sponsee->name }}</div>
                                <small class="text-muted">{{ $sponsee->email }}</small>
                            </div>
                            <span class="badge badge-light-{{ $sponsee->pivot->status === 'active' ? 'success' : 'warning' }}">
                                {{ ucfirst($sponsee->pivot->status) }}
                            </span>
                        </div>
                    @empty
                        <div class="text-center py-3">
                            <p class="f-light mb-2">No members yet. Share your referral link to invite them.</p>
                            <small class="text-muted d-block mb-2">Your link:</small>
                            <code class="small">{{ url('/join/' . auth()->user()->referral_code) }}</code>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
@endsection
