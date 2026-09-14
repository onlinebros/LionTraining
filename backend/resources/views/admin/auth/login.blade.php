<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Quantum Life</title>

    <link rel="icon" type="image/png" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\Asset::v('assets/images/logo/q3-apple-touch-icon-180.png') }}">
    <meta name="theme-color" content="#050505">
    <meta name="color-scheme" content="dark">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/bootstrap.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/color-1.css') }}">
    {{-- Version-stamped: the site sits behind Cloudflare, which caches /assets for
         four hours, so an unversioned theme file keeps serving the old copy after
         every change. partials/q3-head.blade.php does the same for every other
         page; this template has its own <head> and has to do it itself. --}}
    <link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-palette-map.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-theme.css') }}">
</head>
<body class="dark-only q3-theme q3-auth">
    <div class="q3-auth-shell">
        <div class="q3-auth-card">

            <img class="q3-auth-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">

            <h1 class="q3-auth-title">Administration</h1>
            <p class="q3-auth-sub">Sign in to the Quantum Life back office.</p>

            @if($errors->any())
                <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.auth.login.post') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="email">Email Address</label>
                    <input class="form-control @error('email') is-invalid @enderror"
                           id="email" type="email" name="email" value="{{ old('email') }}"
                           placeholder="admin@example.com" required autofocus autocomplete="email">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" type="password" name="password"
                           placeholder="••••••••" required autocomplete="current-password">
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" id="remember" type="checkbox" name="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>

                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>

            <div class="q3-auth-footer">Authorised personnel only.</div>
        </div>
    </div>

    <script src="{{ asset('assets/js/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/js/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
