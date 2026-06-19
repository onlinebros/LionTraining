<div class="page-header">
    <div class="header-wrapper row m-0">
        {{-- Search (decorative, same as admin) --}}
        <form class="form-inline search-full col" action="#" method="get">
            <div class="form-group w-100">
                <div class="Typeahead Typeahead--twitterUsers">
                    <div class="u-posRelative">
                        <input class="demo-input Typeahead-input form-control-plaintext w-100" type="text"
                            placeholder="Search..." name="q" autofocus>
                        <i class="close-search" data-feather="x"></i>
                    </div>
                    <div class="Typeahead-menu"></div>
                </div>
            </div>
        </form>

        {{-- Logo --}}
        <div class="header-logo-wrapper col-auto p-0">
            <div class="logo-wrapper">
                <a href="{{ route('member.dashboard') }}">
                    @php
                        $logoLight = $siteSettings['logo_light'] ?? null;
                        $logoDark  = $siteSettings['logo_dark']  ?? null;
                        $siteName  = $siteSettings['site_name']  ?? 'Lion Training';
                    @endphp
                    @if($logoLight)
                        <img class="img-fluid for-light" src="{{ Storage::url($logoLight) }}" alt="{{ $siteName }}">
                        <img class="img-fluid for-dark"  src="{{ Storage::url($logoDark ?? $logoLight) }}" alt="{{ $siteName }}">
                    @else
                        <img class="img-fluid for-light" src="{{ asset('assets/images/logo/logo.png') }}" alt="{{ $siteName }}">
                        <img class="img-fluid for-dark"  src="{{ asset('assets/images/logo/logo_dark.png') }}" alt="{{ $siteName }}">
                    @endif
                </a>
            </div>
            <div class="toggle-sidebar">
                <i class="status_toggle middle sidebar-toggle" data-feather="align-center"></i>
            </div>
        </div>

        {{-- Left Header --}}
        <div class="left-header col-xxl-5 col-xl-6 col-lg-5 col-md-4 col-sm-3 p-0">
            <div class="notification-slider">
                <div class="d-flex h-100">
                    <img src="{{ asset('assets/images/giftools.gif') }}" alt="gif">
                    <h6 class="mb-0 f-w-400">
                        <span class="font-primary">{{ $siteName }} &mdash; </span>
                        <span class="f-light">Member Portal</span>
                    </h6>
                </div>
            </div>
        </div>

        {{-- Right Nav --}}
        <div class="nav-right col-xxl-7 col-xl-6 col-md-7 col-8 pull-right right-header p-0 ms-auto">
            <ul class="nav-menus">

                {{-- Upgrade badge (free members only) --}}
                @if(auth()->user()->isFreeMember())
                <li>
                    <a href="{{ route('member.training') }}" class="btn btn-primary btn-sm" style="margin-top:2px;">
                        &#9733; Upgrade
                    </a>
                </li>
                @endif

                {{-- Fullscreen --}}
                <li class="fullscreen-body">
                    <span>
                        <svg id="maximize-screen">
                            <use href="{{ asset('assets/svg/icon-sprite.svg#full-screen') }}"></use>
                        </svg>
                    </span>
                </li>

                {{-- Dark mode --}}
                <li>
                    <div class="mode">
                        <svg>
                            <use href="{{ asset('assets/svg/icon-sprite.svg#moon') }}"></use>
                        </svg>
                    </div>
                </li>

                {{-- Support ticket button --}}
                <li>
                    <a href="#" id="supportTicketBtn"
                       data-bs-toggle="modal" data-bs-target="#supportTicketModal"
                       title="Submit a Support Ticket"
                       style="display:flex;align-items:center;color:inherit;opacity:.75;transition:opacity .2s;"
                       onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity='.75'">
                        <svg style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
                            <use href="{{ asset('assets/svg/icon-sprite.svg#stroke-support-tickets') }}"></use>
                        </svg>
                    </a>
                </li>

                {{-- Profile --}}
                @php
                    $roleColors = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger'];
                    $roleName   = auth()->user()->role?->display_name ?? 'Member';
                    $roleKey    = auth()->user()->role?->name ?? 'free_member';
                    $badgeColor = $roleColors[$roleKey] ?? 'info';
                @endphp
                <li class="profile-nav onhover-dropdown pe-0 py-0">
                    <div class="d-flex profile-media align-items-center gap-2">
                        <div style="width:36px;height:36px;border-radius:50%;background:var(--theme-default);color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;flex-shrink:0;">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <div class="flex-grow-1">
                            <span>{{ auth()->user()->name }}</span>
                            <p class="mb-0">{{ $roleName }} <i class="middle fa-solid fa-angle-down"></i></p>
                        </div>
                    </div>
                    <ul class="profile-dropdown onhover-show-div">
                        <li>
                            <a href="{{ route('member.profile') }}">
                                <i data-feather="user"></i><span>Profile</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('member.referrals') }}">
                                <i data-feather="share-2"></i><span>My Referral Link</span>
                            </a>
                        </li>
                        @if(auth()->user()->isAdmin())
                        <li>
                            <a href="{{ route('admin.dashboard') }}">
                                <i data-feather="shield"></i><span>Admin Panel</span>
                            </a>
                        </li>
                        @endif
                        <li>
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('member-logout-form').submit();">
                                <i data-feather="log-out"></i><span>Sign Out</span>
                            </a>
                            <form id="member-logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
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
                <div class="ProfileCard-details">
                    <div class="ProfileCard-realName">@{{name}}</div>
                </div>
            </div>
        </script>
        <script class="empty-template" type="text/x-handlebars-template">
            <div class="EmptyMessage">No results found.</div>
        </script>

    </div>
</div>
