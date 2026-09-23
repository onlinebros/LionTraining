<div class="page-header">
    <div class="header-wrapper row m-0">
        {{-- Search --}}
        <form class="form-inline search-full col" action="#" method="get">
            <div class="form-group w-100">
                <div class="Typeahead Typeahead--twitterUsers">
                    <div class="u-posRelative">
                        <input class="demo-input Typeahead-input form-control-plaintext w-100" type="text"
                            placeholder="Search Anything Here..." name="q" autofocus>
                        <div class="spinner-border Typeahead-spinner" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                        <i class="close-search" data-feather="x"></i>
                    </div>
                    <div class="Typeahead-menu"></div>
                </div>
            </div>
        </form>

        {{-- Logo --}}
        <div class="header-logo-wrapper col-auto p-0">
            <div class="logo-wrapper">
                {{-- Kept in the top bar so the brand survives the mobile
                     breakpoint where the sidebar collapses to a drawer. --}}
                <a href="{{ route('admin.dashboard') }}">
                    <img class="q3-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
                </a>
            </div>
            <div class="toggle-sidebar">
                <i class="status_toggle middle sidebar-toggle" data-feather="align-center"></i>
            </div>
        </div>

        {{-- Left Header --}}
        <div class="left-header col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3 p-0">
            <div class="notification-slider">
                <div class="d-flex h-100 align-items-center">
                    <h6 class="mb-0 f-w-400">
                        <span class="font-primary">Quantum Life &mdash; </span>
                        <span class="f-light">Back Office Administration Panel</span>
                    </h6>
                </div>
            </div>
        </div>

        {{-- Right Nav --}}
        <div class="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
            <ul class="nav-menus">

                {{-- Fullscreen --}}
                <li class="fullscreen-body">
                    <span>
                        <svg id="maximize-screen">
                            <use href="{{ asset('assets/svg/icon-sprite.svg#full-screen') }}"></use>
                        </svg>
                    </span>
                </li>

                {{-- Search toggle --}}
                <li>
                    <span class="header-search">
                        <svg>
                            <use href="{{ asset('assets/svg/icon-sprite.svg#search') }}"></use>
                        </svg>
                    </span>
                </li>

                {{-- The Cuba light/dark switch is deliberately not rendered: the
                     Q3 theme is dark-only, and toggling it off would strip the
                     .dark-only class and leave the UI half-styled. --}}

                {{-- Notifications --}}
                <li class="onhover-dropdown">
                    <div class="notification-box">
                        <svg>
                            <use href="{{ asset('assets/svg/icon-sprite.svg#notification') }}"></use>
                        </svg>
                        <span class="badge rounded-pill badge-success">0</span>
                    </div>
                    <div class="onhover-show-div notification-dropdown">
                        <h6 class="f-18 mb-0 dropdown-title">Notifications</h6>
                        <ul>
                            <li class="text-center p-3">
                                <p class="mb-0 f-light">No new notifications</p>
                            </li>
                        </ul>
                    </div>
                </li>

                {{-- Profile --}}
                <li class="profile-nav onhover-dropdown pe-0 py-0">
                    <div class="d-flex profile-media align-items-center gap-2">
                        <div class="q3-avatar">A</div>
                        <div class="flex-grow-1">
                            <span>Admin</span>
                            <p class="mb-0">Administrator <i class="middle fa-solid fa-angle-down"></i></p>
                        </div>
                    </div>
                    <ul class="profile-dropdown onhover-show-div">
                        @include('partials.landing-preference')
                        <li>
                            <a href="#">
                                <i data-feather="user"></i><span>Account</span>
                            </a>
                        </li>
                        <li>
                            <a href="#">
                                <i data-feather="settings"></i><span>Settings</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('admin.auth.logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                <i data-feather="log-in"></i><span>Log out</span>
                            </a>
                            <form id="logout-form" action="{{ route('admin.auth.logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        </li>
                    </ul>
                </li>

            </ul>
        </div>

        {{-- Typeahead templates --}}
        <script class="result-template" type="text/x-handlebars-template">
            <div class="ProfileCard u-cf">
                <div class="ProfileCard-avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         class="feather feather-airplay m-0">
                        <path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path>
                        <polygon points="12 15 17 21 7 21 12 15"></polygon>
                    </svg>
                </div>
                <div class="ProfileCard-details">
                    <div class="ProfileCard-realName">@{{name}}</div>
                </div>
            </div>
        </script>
        <script class="empty-template" type="text/x-handlebars-template">
            <div class="EmptyMessage">Your search turned up 0 results.</div>
        </script>

    </div>
</div>
