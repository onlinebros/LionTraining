@php
    // Carried on every link so switching section does not silently switch the
    // vendor back to the first one for an account holding several.
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
@endphp

<div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
    <div>
        <div class="logo-wrapper">
            <a href="{{ route('product-partner.dashboard', $ctx) }}">
                <img class="q3-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum 3 Solution">
            </a>
            <div class="back-btn"><i class="fa-solid fa-angle-left"></i></div>
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"></i></div>
        </div>
        <div class="logo-icon-wrapper">
            <a href="{{ route('product-partner.dashboard', $ctx) }}">
                <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="Quantum 3 Solution">
            </a>
        </div>

        <nav class="sidebar-main">
            <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                    <li class="back-btn">
                        <a href="{{ route('product-partner.dashboard', $ctx) }}">
                            <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="Quantum 3 Solution">
                        </a>
                        <div class="mobile-back text-end">
                            <span>Back</span><i class="fa-solid fa-angle-right ps-2" aria-hidden="true"></i>
                        </div>
                    </li>

                    <li class="sidebar-main-title"><div><h6>{{ $vendorName ?? 'Your products' }}</h6></div></li>

                    <li class="sidebar-list">
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('product-partner.dashboard') ? 'active' : '' }}"
                           href="{{ route('product-partner.dashboard', $ctx) }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use></svg>
                            <span>Overview</span>
                        </a>
                    </li>

                    <li class="sidebar-list">
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('product-partner.sales*') ? 'active' : '' }}"
                           href="{{ route('product-partner.sales', $ctx) }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <span>Sales</span>
                        </a>
                    </li>

                    <li class="sidebar-list">
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('product-partner.prospects') ? 'active' : '' }}"
                           href="{{ route('product-partner.prospects', $ctx) }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-charts') }}"></use></svg>
                            <span>Pipeline</span>
                        </a>
                    </li>

                    <li class="sidebar-list">
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('product-partner.statement*') ? 'active' : '' }}"
                           href="{{ route('product-partner.statement', $ctx) }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-price') }}"></use></svg>
                            <span>Statement</span>
                        </a>
                    </li>

                    {{-- The way across.
                         A partner who also sells holds two back offices, and
                         the only thing worse than not having the other one is
                         having it with no way to reach it. Rendered only for
                         accounts that actually hold the other side. --}}
                    @php $me = auth()->user(); @endphp
                    @if ($me?->canUseMemberArea() || $me?->isAdmin())
                        <li class="sidebar-main-title"><div><h6>Switch to</h6></div></li>

                        @if ($me->canUseMemberArea())
                            <li class="sidebar-list">
                                <a class="sidebar-link sidebar-title link-nav" href="{{ route('member.dashboard') }}">
                                    <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-social') }}"></use></svg>
                                    <span>Member Area</span>
                                </a>
                            </li>
                        @endif

                        @if ($me->isAdmin())
                            <li class="sidebar-list">
                                <a class="sidebar-link sidebar-title link-nav" href="{{ route('admin.dashboard') }}">
                                    <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-settings') }}"></use></svg>
                                    <span>Admin Panel</span>
                                </a>
                            </li>
                        @endif
                    @endif
                </ul>
            </div>
        </nav>
    </div>
</div>
