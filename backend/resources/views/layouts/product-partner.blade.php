<!DOCTYPE html>
{{-- Dark by design, like the other two shells — see layouts/member.blade.php. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') | {{ $vendorName ?? 'Partner' }} &amp; Quantum 3</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/fontawesome.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/icofont.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/themify.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/feather-icon.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/scrollbar.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/animate.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/bootstrap.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">
    <link id="color" rel="stylesheet" href="{{ asset('assets/css/color-1.css') }}" media="screen">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/responsive.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}">
    @include('partials.q3-head')
    @stack('styles')
</head>
<body class="dark-only q3-theme">
    <div class="loader-wrapper">
        <div class="loader-index"><span></span></div>
        <svg>
            <defs></defs>
            <filter id="goo">
                <fegaussianblur in="SourceGraphic" stddeviation="11" result="blur"></fegaussianblur>
                <fecolormatrix in="blur" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 19 -9" result="goo"></fecolormatrix>
            </filter>
        </svg>
    </div>

    <div class="tap-top"><i data-feather="chevrons-up"></i></div>

    <div class="page-wrapper compact-wrapper" id="pageWrapper">

        @include('partials.product-partner-header')

        <div class="page-body-wrapper">
            @include('partials.product-partner-sidebar')

            <div class="page-body">
                @if (!empty($viewingAs))
                    {{-- Borrowed eyes. This must never be quiet: the page is
                         indistinguishable from the real thing, which is the
                         point of it and also the reason a screenshot taken here
                         could be passed off as the vendor's own screen. --}}
                    <div class="container-fluid pt-3">
                        <div class="alert alert-info py-2 mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span>
                                Viewing as <strong>{{ $viewingAs->name }}</strong>{{ $viewingAs->email ? ' ('.$viewingAs->email.')' : '' }}.
                                This is exactly what they see. Nothing here can be changed while you are looking through their account.
                            </span>
                            <form method="POST" action="{{ route('admin.product-partners.stop-viewing') }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-light">Stop viewing as them</button>
                            </form>
                        </div>
                    </div>
                @elseif (auth()->user()?->isAdmin())
                    {{-- An admin is not a product partner. Saying so stops a
                         screenshot from this session being read as what the
                         vendor sees, when an admin's grants are wider. --}}
                    <div class="container-fluid pt-3">
                        <div class="alert alert-warning py-2 mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span>
                                You are signed in as an administrator, so you are seeing <strong>every</strong> vendor.
                                To see what one partner sees, use <em>View as</em> on the Product Partners screen.
                            </span>
                            <a href="{{ route('admin.product-partners.index') }}" class="btn btn-sm btn-outline-light">Manage partners</a>
                        </div>
                    </div>
                @endif

                <div class="container-fluid">
                    <div class="page-title">
                        <div class="row">
                            <div class="col-sm-6">
                                <h3>@yield('page-title', 'Overview')</h3>
                                <span class="f-light">{{ $vendorName ?? '' }} &mdash; Quantum 3 Solution</span>
                            </div>
                            <div class="col-sm-6">
                                {{-- Rendered only for an account holding more
                                     than one vendor. See PortalController. --}}
                                @if (!empty($vendorSwitch))
                                    <form method="GET" class="float-sm-end">
                                        <select name="vendor" class="form-select form-select-sm" style="max-width:260px"
                                                onchange="this.form.submit()">
                                            @foreach ($vendorSwitch as $slug => $label)
                                                <option value="{{ $slug }}" @selected(($vendor ?? '') === $slug)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="container-fluid">
                    @if (session('status'))
                        <div class="alert alert-success py-2">{{ session('status') }}</div>
                    @endif
                    @if (session('success'))
                        <div class="alert alert-success py-2">{{ session('success') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
                    @endif

                    @yield('content')
                </div>
            </div>

            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-12 footer-copyright text-center">
                            <p class="mb-0">Copyright &copy; {{ date('Y') }} &mdash; Quantum 3 Solution</p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <script src="{{ asset('assets/js/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/js/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/icons/feather-icon/feather.min.js') }}"></script>
    <script src="{{ asset('assets/js/icons/feather-icon/feather-icon.js') }}"></script>
    <script src="{{ asset('assets/js/scrollbar/simplebar.min.js') }}"></script>
    <script src="{{ asset('assets/js/scrollbar/custom.js') }}"></script>
    <script src="{{ asset('assets/js/config.js') }}"></script>
    <script src="{{ asset('assets/js/sidebar-menu.js') }}"></script>
    <script src="{{ asset('assets/js/script.js') }}"></script>
    @stack('scripts')
</body>
</html>
