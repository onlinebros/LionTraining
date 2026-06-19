<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ $siteSettings['default_mode'] ?? 'light' }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | {{ $siteSettings['site_name'] ?? 'Lion Training' }}</title>
    <link rel="icon" href="{{ asset('assets/images/favicon.png') }}" type="image/x-icon">
    <link rel="shortcut icon" href="{{ asset('assets/images/favicon.png') }}" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Rubik:400,400i,500,500i,700,700i&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,300i,400,400i,500,500i,700,700i,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/fontawesome.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/icofont.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/themify.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/flag-icon.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/feather-icon.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/slick.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/slick-theme.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/scrollbar.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/animate.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/vendors/bootstrap.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">
    <link id="color" rel="stylesheet" href="{{ asset('assets/css/' . ($siteSettings['color_scheme'] ?? 'color-1') . '.css') }}" media="screen">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/responsive.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}">
    @stack('styles')
</head>
<body>
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

        @include('partials.member-header')

        <div class="page-body-wrapper">
            @include('partials.member-sidebar')

            <div class="page-body">
                <div class="container-fluid">
                    <div class="page-title">
                        <div class="row">
                            <div class="col-sm-6">
                                <h3>@yield('page-title', 'Dashboard')</h3>
                            </div>
                            <div class="col-sm-6">
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item">
                                        <a href="{{ route('member.dashboard') }}">
                                            <svg class="stroke-icon">
                                                <use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use>
                                            </svg>
                                        </a>
                                    </li>
                                    @yield('breadcrumb')
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Flash messages --}}
                @if(session('success') || session('status'))
                <div class="container-fluid">
                    <div class="alert alert-success alert-dismissible fade show">
                        {{ session('success') ?? session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                @endif
                @if(session('error'))
                <div class="container-fluid">
                    <div class="alert alert-danger alert-dismissible fade show">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                @endif

                <div class="container-fluid">
                    @yield('content')
                </div>
            </div>

            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-12 footer-copyright text-center">
                            <p class="mb-0">Copyright &copy; {{ date('Y') }} &mdash; {{ $siteSettings['site_name'] ?? 'Lion Training' }}</p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    {{-- Upgrade modal for free members --}}
    @if(auth()->user()->isFreeMember())
    <div class="modal fade" id="upgrade-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center px-4 pb-4">
                    <div style="font-size:2.5rem;margin-bottom:1rem;">&#9733;</div>
                    <h5 class="fw-bold mb-2">Upgrade to Paid</h5>
                    <p class="text-muted mb-3">
                        Unlock training content, advanced analytics, and more.
                        Contact your sponsor or an admin to upgrade your account.
                    </p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got It</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Support ticket modal (available on every page) --}}
    <div class="modal fade" id="supportTicketModal" tabindex="-1" aria-labelledby="supportTicketModalLabel">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="supportTicketModalLabel">
                        <svg style="width:18px;height:18px;vertical-align:-2px;margin-right:6px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
                            <use href="{{ asset('assets/svg/icon-sprite.svg#stroke-support-tickets') }}"></use>
                        </svg>
                        Submit a Support Ticket
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form method="POST" action="{{ route('member.support.store') }}" id="supportTicketForm">
                    @csrf
                    <input type="hidden" name="source_url" id="ticketSourceUrl">

                    <div class="modal-body">

                        @if($errors->any() && old('_ticket_modal'))
                            <div class="alert alert-danger py-2 small">
                                <ul class="mb-0 ps-3">
                                    @foreach($errors->all() as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="ticketSubject">
                                    Subject <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control @if($errors->has('subject') && old('_ticket_modal')) is-invalid @endif"
                                       id="ticketSubject" name="subject"
                                       value="{{ old('_ticket_modal') ? old('subject') : '' }}"
                                       placeholder="Brief description of your issue" required>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="ticketCategory">
                                    Category <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="ticketCategory" name="category" required>
                                    @foreach(\App\Models\SupportTicket::CATEGORIES as $val => $label)
                                        <option value="{{ $val }}"
                                            {{ (old('_ticket_modal') ? old('category') : 'general') === $val ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="ticketPriority">
                                    Priority <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="ticketPriority" name="priority" required>
                                    @foreach(\App\Models\SupportTicket::PRIORITIES as $val => $label)
                                        <option value="{{ $val }}"
                                            {{ (old('_ticket_modal') ? old('priority') : 'normal') === $val ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="ticketBody">
                                    Description <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control @if($errors->has('body') && old('_ticket_modal')) is-invalid @endif"
                                          id="ticketBody" name="body" rows="6"
                                          placeholder="Describe your issue in detail…" required>{{ old('_ticket_modal') ? old('body') : '' }}</textarea>
                            </div>

                            <div class="col-12">
                                <div id="ticketPageContext" class="alert alert-light py-2 small mb-0" style="display:none;">
                                    <i data-feather="link" style="width:13px;height:13px;vertical-align:-1px;"></i>
                                    <strong>Page:</strong>
                                    <span id="ticketPageContextUrl" class="text-muted ms-1"></span>
                                </div>
                            </div>
                        </div>

                        {{-- Hidden flag so we know this POST came from the modal --}}
                        <input type="hidden" name="_ticket_modal" value="1">
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i data-feather="send" style="width:14px;height:14px;"></i> Submit Ticket
                        </button>
                    </div>
                </form>
            </div>
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
    <script src="{{ asset('assets/js/sidebar-pin.js') }}"></script>
    <script src="{{ asset('assets/js/slick/slick.min.js') }}"></script>
    <script src="{{ asset('assets/js/slick/slick.js') }}"></script>
    <script src="{{ asset('assets/js/header-slick.js') }}"></script>
    <script src="{{ asset('assets/js/script.js') }}"></script>
    @stack('scripts')
    <script>
    (function () {
        var btn    = document.getElementById('supportTicketBtn');
        var urlInput  = document.getElementById('ticketSourceUrl');
        var ctxBox    = document.getElementById('ticketPageContext');
        var ctxLabel  = document.getElementById('ticketPageContextUrl');

        function setPageContext(url) {
            if (urlInput)  urlInput.value       = url;
            if (ctxLabel)  ctxLabel.textContent = url;
            if (ctxBox)    ctxBox.style.display = '';
        }

        // Capture URL at the moment the button is clicked
        if (btn) {
            btn.addEventListener('click', function () {
                setPageContext(window.location.href);
            });
        }

        // Auto-reopen modal if there were validation errors on the ticket form
        @if($errors->any() && old('_ticket_modal'))
        var modal = new bootstrap.Modal(document.getElementById('supportTicketModal'));
        modal.show();
        // Restore the source_url that was submitted
        setPageContext('{{ old('source_url', '') }}');
        @endif

        // Reinit feather icons after modal renders
        document.getElementById('supportTicketModal').addEventListener('shown.bs.modal', function () {
            if (typeof feather !== 'undefined') feather.replace();
        });
    })();
    </script>
</body>
</html>
