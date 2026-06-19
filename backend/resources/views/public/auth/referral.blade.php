@extends('layouts.public')
@section('title', 'You\'ve Been Invited — Lion Training')

@push('styles')
<style>
    .sponsor-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: var(--theme-default, #7366ff); color: #fff;
        border-radius: 4px; padding: 4px 12px; font-size: .8rem;
        font-weight: 600; letter-spacing: .5px; margin-bottom: 14px;
    }
    .sponsor-info-card {
        background: #f0eeff; border: 1px solid #c9c2ff;
        border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;
        display: flex; align-items: center; gap: 12px;
    }
    .sponsor-info-card .sponsor-avatar {
        width: 42px; height: 42px; border-radius: 50%;
        background: #7366ff; color: #fff; display: flex;
        align-items: center; justify-content: center;
        font-size: 1.1rem; font-weight: 700; flex-shrink: 0;
    }
    .sponsor-info-card .sponsor-name { font-weight: 700; font-size: .95rem; color: #2d3748; }
    .sponsor-info-card .sponsor-sub { font-size: .8rem; color: #718096; }
    .divider { border-top: 1px dashed #dee2e6; margin: 18px 0; }
</style>
@endpush

@section('content')
<div class="container-fluid p-0">
    <div class="row m-0">
        <div class="col-12 p-0">
            <div class="login-card login-dark">
                <div>
                    <div>
                        <a class="logo" href="{{ route('home') }}">
                            <img class="img-fluid for-light" src="{{ asset('assets/images/logo/logo.png') }}" alt="Lion Training">
                            <img class="img-fluid for-dark" src="{{ asset('assets/images/logo/logo_dark.png') }}" alt="Lion Training">
                        </a>
                    </div>
                    <div class="login-main create-account">

                        {{-- Sponsor badge + info --}}
                        <div class="sponsor-badge">
                            <i data-feather="star" style="width:13px;height:13px;"></i>
                            You've been invited
                        </div>

                        <div class="sponsor-info-card">
                            <div class="sponsor-avatar">
                                {{ strtoupper(substr($sponsor->name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="sponsor-name">{{ $sponsor->name }}</div>
                                <div class="sponsor-sub">is inviting you to join Lion Training as their sponsored member.</div>
                            </div>
                        </div>

                        <form class="theme-form" method="POST" action="{{ route('join.post', $sponsor->referral_code) }}">
                            @csrf
                            <input type="hidden" name="referral_code" value="{{ $sponsor->referral_code }}">

                            <h4>Create your account</h4>
                            <p>Fill in your details to accept the invitation and get started.</p>

                            @if($errors->any())
                                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
                            @endif

                            <div class="form-group">
                                <label class="col-form-label pt-0">Full Name</label>
                                <input class="form-control @error('name') is-invalid @enderror"
                                       type="text" name="name" value="{{ old('name') }}"
                                       placeholder="Your full name" required autofocus>
                            </div>

                            <div class="form-group">
                                <label class="col-form-label">Email Address</label>
                                <input class="form-control @error('email') is-invalid @enderror"
                                       type="email" name="email" value="{{ old('email') }}"
                                       placeholder="you@example.com" required>
                            </div>

                            <div class="form-group">
                                <label class="col-form-label">Password</label>
                                <div class="form-input position-relative">
                                    <input class="form-control @error('password') is-invalid @enderror"
                                           type="password" name="password"
                                           placeholder="Min. 8 characters" required>
                                    <div class="show-hide"><span class="show"></span></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-form-label">Confirm Password</label>
                                <div class="form-input position-relative">
                                    <input class="form-control" type="password" name="password_confirmation"
                                           placeholder="Re-enter password" required>
                                    <div class="show-hide"><span class="show"></span></div>
                                </div>
                            </div>

                            <div class="divider"></div>

                            <div class="form-group mb-0">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label text-muted" for="terms">
                                        I agree to the <a href="#">Terms &amp; Conditions</a>
                                    </label>
                                    <div class="invalid-feedback">You must agree before continuing.</div>
                                </div>
                                <button class="btn btn-primary btn-block w-100" type="submit">
                                    Accept Invitation &amp; Join
                                </button>
                            </div>

                            <p class="mt-4 mb-0 text-center">
                                Already have an account?
                                <a class="ms-2" href="{{ route('login') }}">Sign in</a>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
