<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access pending | Quantum 3 Solution</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/bootstrap.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}">
    @include('partials.q3-head')
</head>
<body class="dark-only q3-theme">
    <div class="container" style="max-width:560px; padding-top:12vh;">
        <div class="card">
            <div class="card-body text-center p-5">
                <img class="q3-logo mb-4" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum 3 Solution">

                <h5 class="mb-3">Your account is set up, but no products are linked to it yet</h5>

                {{-- Not an error page. The account is fine; it is waiting on
                     somebody at our end to link a product line to it, which is
                     a deliberate second step. --}}
                <p class="f-light mb-4">
                    A Quantum 3 administrator needs to link your product line to this account
                    before your sales and statement can be shown. This usually takes a moment —
                    if you have been waiting, reply to whoever set your account up.
                </p>

                @if (!empty($viewingAs))
                    {{-- An admin looking through an account that has nothing
                         linked. This page is the answer to their question, so
                         it says so and hands them the way out. --}}
                    <div class="alert alert-info py-2 text-start small">
                        You are viewing as <strong>{{ $viewingAs->name }}</strong>. This is the page they
                        see until a vendor's products are linked to their account.
                    </div>
                    <form method="POST" action="{{ route('admin.product-partners.stop-viewing') }}">
                        @csrf
                        <button class="btn btn-primary btn-sm">Stop viewing as them</button>
                    </form>
                @else
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button class="btn btn-outline-light btn-sm">Log out</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
