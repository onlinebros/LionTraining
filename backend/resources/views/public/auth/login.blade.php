@extends('layouts.public')
@section('title', 'Sign In — Lion Training')

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
                    <div class="login-main">
                        <form class="theme-form" method="POST" action="{{ route('login.post') }}">
                            @csrf
                            <h4>Sign in to your account</h4>
                            <p>Welcome back! Enter your credentials below.</p>

                            @if($errors->any())
                                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
                            @endif

                            @if(session('status'))
                                <div class="alert alert-success py-2">{{ session('status') }}</div>
                            @endif

                            <div class="form-group">
                                <label class="col-form-label">Email Address</label>
                                <input class="form-control @error('email') is-invalid @enderror"
                                       type="email" name="email" value="{{ old('email') }}"
                                       placeholder="you@example.com" required autofocus>
                            </div>

                            <div class="form-group">
                                <label class="col-form-label">Password
                                    <span class="float-end">
                                        <a href="#" class="link">Forgot password?</a>
                                    </span>
                                </label>
                                <div class="form-input position-relative">
                                    <input class="form-control" type="password" name="password"
                                           placeholder="········" required>
                                    <div class="show-hide"><span class="show"></span></div>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <div class="checkbox p-0">
                                    <input id="remember" type="checkbox" name="remember">
                                    <label class="text-muted" for="remember">Remember me</label>
                                </div>
                                <button class="btn btn-primary btn-block w-100 mt-3" type="submit">Sign In</button>
                            </div>

                            <p class="mt-4 mb-0 text-center">
                                Don't have an account?
                                <a class="ms-2" href="{{ route('register') }}">Create account</a>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
