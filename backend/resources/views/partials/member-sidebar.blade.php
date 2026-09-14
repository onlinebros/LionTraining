<div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
    <div>
        <div class="logo-wrapper">
            @php
                $siteName = $siteSettings['site_name'] ?? 'Quantum Life';
                // An admin-uploaded logo still wins; otherwise the transparent Q3
                // mark sits straight on the black panel. Sizing is handled by
                // .q3-logo in q3-theme.css so proportions are never distorted.
                $sidebarLogo = ($siteSettings['logo_dark'] ?? null)
                    ? Storage::url($siteSettings['logo_dark'])
                    : (($siteSettings['logo_light'] ?? null)
                        ? Storage::url($siteSettings['logo_light'])
                        : \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png'));
            @endphp
            <a href="{{ route('member.dashboard') }}">
                <img class="q3-logo" src="{{ $sidebarLogo }}" alt="{{ $siteName }}">
            </a>
            <div class="back-btn"><i class="fa-solid fa-angle-left"></i></div>
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"></i></div>
        </div>
        <div class="logo-icon-wrapper">
            <a href="{{ route('member.dashboard') }}">
                <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="{{ $siteName }}">
            </a>
        </div>
        <nav class="sidebar-main">
            <div class="left-arrow" id="left-arrow"><i data-feather="arrow-left"></i></div>
            <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                    <li class="back-btn">
                        <a href="{{ route('member.dashboard') }}">
                            <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="{{ $siteName }}">
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

                    {{-- My Team --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.network') ? 'active' : '' }}"
                           href="{{ route('member.network') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-social') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-social') }}"></use></svg>
                            <span>My Team</span>
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

                    {{-- Product sales — third-party vendor referrals. Deliberately
                         not behind the pre-launch guard: these are other people's
                         products on other people's checkouts, so they can be sold
                         before our own launch. --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('member.sales.*') ? 'active' : '' }}"
                           href="{{ route('member.sales.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Product Sales</span>
                        </a>
                    </li>

                    {{-- Commissions — hidden while the pre-launch guard has the
                         section closed. Both this and the middleware read
                         App\Support\Prelaunch, so a section can never be visible
                         in the menu while its URLs are shut, or vice versa. --}}
                    @if (\App\Support\Prelaunch::open('commissions', auth()->user()))
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
                    @endif

                    {{-- CRM --}}
                    @if (\App\Support\Prelaunch::open('crm', auth()->user()))
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
                    @endif

                    {{-- Billing — never behind the pre-launch guard or the
                         subscription gate. A partner must always be able to
                         reach the screen that fixes their billing state. --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.billing.*') ? 'active' : '' }}"
                           href="{{ route('member.billing.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-charts') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-charts') }}"></use></svg>
                            <span>Billing</span>
                        </a>
                    </li>

                    {{-- Get Paid — where a partner sets up the Stripe account their
                         commissions are paid into. --}}
                    @if (config('stripe.connect.enabled'))
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('member.payouts.*') ? 'active' : '' }}"
                           href="{{ route('member.payouts.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Get Paid</span>
                            @if (! auth()->user()->isAdmin() && ! auth()->user()->canReceivePayouts())
                                <span class="badge bg-warning text-dark ms-1">setup</span>
                            @endif
                        </a>
                    </li>
                    @endif

                    {{-- Support — deliberately never closed. People have the most
                         questions during the busiest signup period. --}}
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
