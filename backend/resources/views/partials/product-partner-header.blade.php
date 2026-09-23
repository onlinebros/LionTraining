@php
    $partner = auth()->user();
@endphp

<div class="page-header">
    <div class="header-wrapper row m-0">

        {{-- Logo. Ours, not the vendor's: this is our application, shown to
             them, and a co-branded header would be a claim neither company has
             agreed to. --}}
        <div class="header-logo-wrapper col-auto p-0">
            <div class="logo-wrapper">
                <a href="{{ route('product-partner.dashboard') }}">
                    <img class="q3-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum 3 Solution">
                </a>
            </div>
            <div class="toggle-sidebar">
                <i class="status_toggle middle sidebar-toggle" data-feather="align-center"></i>
            </div>
        </div>

        <div class="left-header col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3 p-0">
            <div class="d-flex h-100 align-items-center">
                <h6 class="mb-0 f-w-400">
                    <span class="font-primary">Product Partner &mdash; </span>
                    <span class="f-light">{{ $vendorName ?? '' }}</span>
                </h6>
            </div>
        </div>

        <div class="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
            <ul class="nav-menus">
                <li class="fullscreen-body">
                    <span>
                        <svg id="maximize-screen"><use href="{{ asset('assets/svg/icon-sprite.svg#full-screen') }}"></use></svg>
                    </span>
                </li>

                <li class="profile-nav onhover-dropdown pe-0 py-0">
                    <div class="d-flex profile-media align-items-center gap-2">
                        <div class="q3-avatar">{{ strtoupper(substr((string) $partner?->name, 0, 1)) }}</div>
                        <div class="flex-grow-1">
                            <span>{{ $partner?->name }}</span>
                            <p class="mb-0">{{ $vendorName ?? 'Partner' }} <i class="middle fa-solid fa-angle-down"></i></p>
                        </div>
                    </div>
                    <ul class="profile-dropdown onhover-show-div">
                        @include('partials.landing-preference')
                        <li>
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('partner-logout').submit();">
                                <i data-feather="log-in"></i><span>Log out</span>
                            </a>
                            <form id="partner-logout" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</div>
