@extends('layouts.public')
@section('title', 'Create Account — Lion Training')

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
                        <form class="theme-form" method="POST" action="{{ route('register.post') }}">
                            @csrf
                            <h4>Create your account</h4>
                            <p>Join Lion Training to manage or receive sponsorships.</p>

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

                            <div class="form-group mb-0">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label text-muted" for="terms">
                                        I agree to the <a href="#">Terms &amp; Conditions</a>
                                    </label>
                                    <div class="invalid-feedback">You must agree before continuing.</div>
                                </div>
                                <button class="btn btn-primary btn-block w-100" type="submit">Create Account</button>
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
