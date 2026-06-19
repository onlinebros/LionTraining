<div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
    <div>
        <div class="logo-wrapper">
            @php
                $logoLight = $siteSettings['logo_light'] ?? null;
                $logoDark  = $siteSettings['logo_dark']  ?? null;
                $siteName  = $siteSettings['site_name']  ?? 'Lion Training';
            @endphp
            <a href="{{ route('member.dashboard') }}">
                @if($logoLight)
                    <img class="img-fluid for-light" src="{{ Storage::url($logoLight) }}" alt="{{ $siteName }}">
                    <img class="img-fluid for-dark"  src="{{ Storage::url($logoDark ?? $logoLight) }}" alt="{{ $siteName }}">
                @else
                    <img class="img-fluid for-light" src="{{ asset('assets/images/logo/logo.png') }}" alt="{{ $siteName }}">
                    <img class="img-fluid for-dark"  src="{{ asset('assets/images/logo/logo_dark.png') }}" alt="{{ $siteName }}">
                @endif
            </a>
            <div class="back-btn"><i class="fa-solid fa-angle-left"></i></div>
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"></i></div>
        </div>
        <div class="logo-icon-wrapper">
            <a href="{{ route('member.dashboard') }}">
                <img class="img-fluid" src="{{ asset('assets/images/logo/logo-icon.png') }}" alt="">
            </a>
        </div>
        <nav class="sidebar-main">
            <div class="left-arrow" id="left-arrow"><i data-feather="arrow-left"></i></div>
            <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                    <li class="back-btn">
                        <a href="{{ route('member.dashboard') }}">
                            <img class="img-fluid" src="{{ asset('assets/images/logo/logo-icon.png') }}" alt="">
                        </a>
                        <div class="mobile-back text-end">
                            <span>Back</span><i class="fa-solid fa-angle-right ps-2" aria-hidden="true"></i>
                        </div>
                    </li>

                    {{-- ==================== MEMBER ==================== --}}
                    <li class="pin-title sidebar-main-title">
                        <div><h6>Pinned</h6></div>
                    </li>
                    <li class="sidebar-main-title">
                        <div><h6>Member Area</h6></div>
                    </li>

                    {{-- Dashboard --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.dashboard') ? 'active' : '' }}"
                           href="{{ route('member.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-home') }}"></use></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    {{-- My Network --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.network') ? 'active' : '' }}"
                           href="{{ route('member.network') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-social') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-social') }}"></use></svg>
                            <span>My Network</span>
                        </a>
                    </li>

                    {{-- Referrals --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.referrals') ? 'active' : '' }}"
                           href="{{ route('member.referrals') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-contact') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-contact') }}"></use></svg>
                            <span>Referrals</span>
                        </a>
                    </li>

                    {{-- Training --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        @if(auth()->user()->isPaidOrAbove())
                            <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.training') ? 'active' : '' }}"
                               href="{{ route('member.training') }}">
                                <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-learning') }}"></use></svg>
                                <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-learning') }}"></use></svg>
                                <span>Training</span>
                            </a>
                        @else
                            <a class="sidebar-link sidebar-title link-nav" href="{{ route('member.training') }}"
                               title="Upgrade to access training">
                                <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-learning') }}"></use></svg>
                                <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-learning') }}"></use></svg>
                                <span>Training <span class="badge badge-light-warning ms-1" style="font-size:.6rem;padding:2px 6px;">Paid</span></span>
                            </a>
                        @endif
                    </li>

                    {{-- Commissions --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('member.commissions.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Commissions</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('member.commissions.index') }}"
                                   class="{{ request()->routeIs('member.commissions.index') ? 'active' : '' }}">Overview</a></li>
                            <li><a href="{{ route('member.commissions.history') }}"
                                   class="{{ request()->routeIs('member.commissions.history') ? 'active' : '' }}">History</a></li>
                            <li><a href="{{ route('member.commissions.payouts') }}"
                                   class="{{ request()->routeIs('member.commissions.payouts') ? 'active' : '' }}">Payouts</a></li>
                        </ul>
                    </li>

                    {{-- CRM --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('member.crm.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-task') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-task') }}"></use></svg>
                            <span>My CRM</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('member.crm.dashboard') }}"
                                   class="{{ request()->routeIs('member.crm.dashboard') ? 'active' : '' }}">Dashboard</a></li>
                            <li><a href="{{ route('member.crm.contacts.index') }}"
                                   class="{{ request()->routeIs('member.crm.contacts.index') ? 'active' : '' }}">My Contacts</a></li>
                            <li><a href="{{ route('member.crm.contacts.create') }}"
                                   class="{{ request()->routeIs('member.crm.contacts.create') ? 'active' : '' }}">Add Contact</a></li>
                        </ul>
                    </li>

                    {{-- Support --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.support.*') ? 'active' : '' }}"
                           href="{{ route('member.support.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-support-tickets') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-support-tickets') }}"></use></svg>
                            <span>Support</span>
                        </a>
                    </li>

                    {{-- ==================== ACCOUNT ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Account</h6></div>
                    </li>

                    {{-- Profile --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.profile') ? 'active' : '' }}"
                           href="{{ route('member.profile') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-user') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-user') }}"></use></svg>
                            <span>Profile</span>
                        </a>
                    </li>

                    {{-- Admin Panel link (if user is admin) --}}
                    @if(auth()->user()->isAdmin())
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="{{ route('admin.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-authentication') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-authentication') }}"></use></svg>
                            <span>Admin Panel</span>
                        </a>
                    </li>
                    @endif

                </ul>
            </div>
            <div class="right-arrow" id="right-arrow"><i data-feather="arrow-right"></i></div>
        </nav>
    </div>
</div>
