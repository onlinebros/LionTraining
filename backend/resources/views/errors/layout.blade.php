{{--
    The shell every error page renders in.

    Deliberately standalone rather than extending layouts.member or
    layouts.public. Those shells assume a working request: the member sidebar
    reads the signed-in user, their role and their counts, and an error page
    that needs any of that is an error page that can fail while reporting a
    failure. This one needs the stylesheet and nothing else.

    Authentication is *consulted*, not required, and only inside a try. With
    SESSION_DRIVER=database in production, asking who is signed in is a database
    read — which is one of the things that may be broken. When that read throws,
    the page renders the signed-out links instead of becoming a second error.
--}}
@php
    try {
        $signedIn = auth()->check();
    } catch (\Throwable) {
        $signedIn = false;
    }
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') | Quantum Life</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/bootstrap.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/color-1.css') }}">
    @include('partials.q3-head')
    <style>
        body.q3-error {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .q3-error-card {
            max-width: 40rem;
            width: 100%;
            text-align: center;
        }
        .q3-error-code {
            font-size: clamp(3.5rem, 12vw, 6rem);
            font-weight: 700;
            line-height: 1;
            margin-bottom: .5rem;
            opacity: .35;
            letter-spacing: -.02em;
        }
        .q3-error-title { font-size: 1.5rem; font-weight: 600; margin-bottom: .75rem; }
        .q3-error-body  { opacity: .75; margin-bottom: 1.75rem; }
        .q3-error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            justify-content: center;
        }
        .q3-error-ref {
            margin-top: 1.75rem;
            font-size: .8125rem;
            opacity: .55;
        }
        .q3-error-ref code { font-size: .8125rem; }
    </style>
</head>
<body class="q3-auth q3-error">
    <main class="q3-error-card">
        <div class="q3-error-code">@yield('code')</div>
        <h1 class="q3-error-title">@yield('title')</h1>
        <div class="q3-error-body">@yield('body')</div>

        {{--
            The point of the page. Somebody who hits an error still has a
            session, a team and an unfinished task; leaving them on a dead end
            means closing the tab. These are the ways back in, chosen by whether
            we can tell who they are.
        --}}
        <div class="q3-error-actions">
            @if($signedIn)
                <a href="{{ route('member.dashboard') }}" class="btn btn-primary">Go to my dashboard</a>
                <a href="{{ route('member.network') }}" class="btn btn-outline-light">My Team</a>
                <a href="{{ route('member.support.index') }}" class="btn btn-outline-light">Contact support</a>
            @else
                <a href="{{ route('home') }}" class="btn btn-primary">Back to the home page</a>
                <a href="{{ route('login') }}" class="btn btn-outline-light">Log in</a>
            @endif
            {{-- Last, and always: wherever they were is often where they want
                 to be, and a refresh fixes the transient half of these. --}}
            <button type="button" class="btn btn-outline-light" onclick="history.back()">Go back</button>
        </div>

        @hasSection('reference')
            <p class="q3-error-ref">@yield('reference')</p>
        @endif
    </main>
</body>
</html>
