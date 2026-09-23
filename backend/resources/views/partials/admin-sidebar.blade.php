<div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
    <div>
        <div class="logo-wrapper">
            <a href="{{ route('admin.dashboard') }}">
                <img class="q3-logo" src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
            </a>
            <div class="back-btn"><i class="fa-solid fa-angle-left"></i></div>
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"></i></div>
        </div>
        <div class="logo-icon-wrapper">
            <a href="{{ route('admin.dashboard') }}">
                <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="Quantum Life">
            </a>
        </div>
        <nav class="sidebar-main">
            <div class="left-arrow" id="left-arrow"><i data-feather="arrow-left"></i></div>
            <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                    <li class="back-btn">
                        <a href="{{ route('admin.dashboard') }}">
                            <img class="q3-logo-icon" src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="Quantum Life">
                        </a>
                        <div class="mobile-back text-end">
                            <span>Back</span><i class="fa-solid fa-angle-right ps-2" aria-hidden="true"></i>
                        </div>
                    </li>

                    {{-- ==================== GENERAL ==================== --}}
                    <li class="pin-title sidebar-main-title">
                        <div><h6>Pinned</h6></div>
                    </li>
                    <li class="sidebar-main-title">
                        <div><h6>General</h6></div>
                    </li>

                    {{-- Dashboard --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                           href="{{ route('admin.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-home') }}"></use></svg>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    {{-- Member area — admins land on the admin dashboard and
                         otherwise have no way across to the partner-facing
                         product, which is most of what there is to look at. --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="{{ route('member.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-social') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-social') }}"></use></svg>
                            <span>Member Area</span>
                        </a>
                    </li>

                    {{-- Users --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-user') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-user') }}"></use></svg>
                            <span>Users</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.index') ? 'active' : '' }}">User List</a></li>
                            <li><a href="{{ route('admin.users.create') }}" class="{{ request()->routeIs('admin.users.create') ? 'active' : '' }}">Add User</a></li>
                        </ul>
                    </li>

                    {{-- Sponsors --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.sponsors.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-social') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-social') }}"></use></svg>
                            <span>Sponsors</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.sponsors.index') }}" class="{{ request()->routeIs('admin.sponsors.index') ? 'active' : '' }}">Sponsor List</a></li>
                            <li><a href="{{ route('admin.sponsors.relationships') }}" class="{{ request()->routeIs('admin.sponsors.relationships') ? 'active' : '' }}">Relationships</a></li>
                        </ul>
                    </li>

                    {{-- Partner Spots — imported positions and the lists they came
                         from. Super admin only: committing an import writes
                         permanent genealogy, and reissuing a code hands over a
                         position. --}}
                    @if(auth()->check() && auth()->user()->isSuperAdmin())
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.partners.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-user') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-user') }}"></use></svg>
                            <span>Partner Spots</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.partners.spots') }}"
                                   class="{{ request()->routeIs('admin.partners.spots') ? 'active' : '' }}">Claimed / Unclaimed</a></li>
                            <li><a href="{{ route('admin.partners.activations') }}"
                                   class="{{ request()->routeIs('admin.partners.activations*') ? 'active' : '' }}">Activations &amp; Sales</a></li>
                            <li><a href="{{ route('admin.partners.imports.index') }}"
                                   class="{{ request()->routeIs('admin.partners.imports.*') ? 'active' : '' }}">Imports</a></li>
                            <li><a href="{{ route('admin.partners.companies.index') }}"
                                   class="{{ request()->routeIs('admin.partners.companies.*') ? 'active' : '' }}">Companies</a></li>
                            <li><a href="{{ route('admin.partners.webhooks.index') }}"
                                   class="{{ request()->routeIs('admin.partners.webhooks.*') ? 'active' : '' }}">Webhooks</a></li>
                        </ul>
                    </li>
                    @endif

                    {{-- CRM --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.crm.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-task') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-task') }}"></use></svg>
                            <span>CRM</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.crm.dashboard') }}"
                                   class="{{ request()->routeIs('admin.crm.dashboard') ? 'active' : '' }}">Dashboard</a></li>
                            <li><a href="{{ route('admin.crm.contacts.index') }}"
                                   class="{{ request()->routeIs('admin.crm.contacts.*') ? 'active' : '' }}">All Contacts</a></li>
                            <li><a href="{{ route('admin.crm.contacts.create') }}"
                                   class="{{ request()->routeIs('admin.crm.contacts.create') ? 'active' : '' }}">Add Contact</a></li>
                        </ul>
                    </li>

                    {{-- Training --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.training.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-video') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-video') }}"></use></svg>
                            <span>Training</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.training.categories.index') }}"
                                   class="{{ request()->routeIs('admin.training.categories.*') ? 'active' : '' }}">Categories</a></li>
                            <li><a href="{{ route('admin.training.lessons.index') }}"
                                   class="{{ request()->routeIs('admin.training.lessons.*') ? 'active' : '' }}">Lessons</a></li>
                            <li><a href="{{ route('admin.training.lessons.create') }}"
                                   class="{{ request()->routeIs('admin.training.lessons.create') ? 'active' : '' }}">New Lesson</a></li>
                        </ul>
                    </li>

                    {{-- Video Library --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.video-assets.*') || request()->routeIs('admin.kartra.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-gallery') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-gallery') }}"></use></svg>
                            <span>Video Library</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.video-assets.index') }}"
                                   class="{{ request()->routeIs('admin.video-assets.*') ? 'active' : '' }}">All Videos</a></li>
                            <li><a href="{{ route('admin.kartra.index') }}"
                                   class="{{ request()->routeIs('admin.kartra.*') ? 'active' : '' }}">Kartra Import</a></li>
                        </ul>
                    </li>

                    {{-- Screen recordings --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.screen-recordings.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-widget') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-widget') }}"></use></svg>
                            <span>Recording Studio</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.screen-recordings.studio') }}"
                                   class="{{ request()->routeIs('admin.screen-recordings.studio') ? 'active' : '' }}">Record</a></li>
                            <li><a href="{{ route('admin.screen-recordings.upload') }}"
                                   class="{{ request()->routeIs('admin.screen-recordings.upload') ? 'active' : '' }}">Upload a Video</a></li>
                            <li><a href="{{ route('admin.screen-recordings.combine') }}"
                                   class="{{ request()->routeIs('admin.screen-recordings.combine') ? 'active' : '' }}">Combine Videos</a></li>
                            <li><a href="{{ route('admin.screen-recordings.index') }}"
                                   class="{{ request()->routeIs('admin.screen-recordings.index') || request()->routeIs('admin.screen-recordings.show') ? 'active' : '' }}">Video Library</a></li>
                        </ul>
                    </li>

                    {{-- Presentations --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.presentations.*', 'admin.funnels.*', 'admin.cta-items.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-coming-soon') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-coming-soon') }}"></use></svg>
                            <span>Presentations</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.presentations.create') }}"
                                   class="{{ request()->routeIs('admin.presentations.create') ? 'active' : '' }}">Schedule One</a></li>
                            <li><a href="{{ route('admin.presentations.series.index') }}"
                                   class="{{ request()->routeIs('admin.presentations.series.*') ? 'active' : '' }}">Repeating Schedules</a></li>
                            <li><a href="{{ route('admin.presentations.prospects') }}"
                                   class="{{ request()->routeIs('admin.presentations.prospects') ? 'active' : '' }}">Prospects</a></li>
                            <li><a href="{{ route('admin.presentations.index') }}"
                                   class="{{ request()->routeIs('admin.presentations.index') || request()->routeIs('admin.presentations.show') ? 'active' : '' }}">All Presentations</a></li>
                            <li><a href="{{ route('admin.funnels.index') }}"
                                   class="{{ request()->routeIs('admin.funnels.*') ? 'active' : '' }}">Funnels</a></li>
                            <li><a href="{{ route('member.presentations.live') }}">Your Rooms</a></li>
                            <li><a href="{{ route('admin.cta-items.index') }}"
                                   class="{{ request()->routeIs('admin.cta-items.*') ? 'active' : '' }}">Calls To Action</a></li>
                        </ul>
                    </li>

                    {{-- Billing oversight (C3) --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.billing.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-charts') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-charts') }}"></use></svg>
                            <span>Billing</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.billing.subscriptions') }}"
                                   class="{{ request()->routeIs('admin.billing.subscriptions') ? 'active' : '' }}">Subscriptions</a></li>
                            <li><a href="{{ route('admin.billing.webhooks') }}"
                                   class="{{ request()->routeIs('admin.billing.webhooks') ? 'active' : '' }}">Webhooks</a></li>
                            <li><a href="{{ route('admin.billing.payout-accounts.index') }}"
                                   class="{{ request()->routeIs('admin.billing.payout-accounts.*') ? 'active' : '' }}">Payout Accounts</a></li>
                        </ul>
                    </li>

                    {{-- Vendor referrals --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.vendor-leads.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Vendor Orders</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.vendor-leads.index') }}"
                                   class="{{ request()->routeIs('admin.vendor-leads.index') ? 'active' : '' }}">All Orders</a></li>
                            <li><a href="{{ route('admin.vendor-leads.reconciliation') }}"
                                   class="{{ request()->routeIs('admin.vendor-leads.reconciliation') ? 'active' : '' }}">Reconciliation</a></li>
                            <li><a href="{{ route('admin.vendor-leads.promotion') }}"
                                   class="{{ request()->routeIs('admin.vendor-leads.promotion') ? 'active' : '' }}">Promotion</a></li>
                            {{-- The vendor's own people and what they may see. --}}
                            <li><a href="{{ route('admin.product-partners.index') }}"
                                   class="{{ request()->routeIs('admin.product-partners.index') ? 'active' : '' }}">Product Partners</a></li>
                            <li><a href="{{ route('admin.product-partners.payments') }}"
                                   class="{{ request()->routeIs('admin.product-partners.payments') ? 'active' : '' }}">Vendor Payments</a></li>
                            {{-- The vendor-facing portal itself. Reachable from
                                 anywhere in admin, the same way the Member Area
                                 link above exists — an admin who can only get
                                 to a section through one screen does not know
                                 the section is there. --}}
                            <li><a href="{{ route('product-partner.dashboard') }}"
                                   class="{{ request()->routeIs('product-partner.*') ? 'active' : '' }}">Open Partner Portal</a></li>
                        </ul>
                    </li>

                    {{-- Commissions --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.commission-*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Commissions</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.commission-plans.index') }}"
                                   class="{{ request()->routeIs('admin.commission-plans.*') ? 'active' : '' }}">Plans</a></li>
                            <li><a href="{{ route('admin.commission-ledger.index') }}"
                                   class="{{ request()->routeIs('admin.commission-ledger.*') ? 'active' : '' }}">Ledger</a></li>
                            <li><a href="{{ route('admin.commission-payouts.index') }}"
                                   class="{{ request()->routeIs('admin.commission-payouts.*') ? 'active' : '' }}">Payouts</a></li>
                            <li><a href="{{ route('admin.commission-clawbacks.index') }}"
                                   class="{{ request()->routeIs('admin.commission-clawbacks.*') ? 'active' : '' }}">Clawbacks</a></li>
                        </ul>
                    </li>

                    {{-- Support Tickets --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link {{ request()->routeIs('admin.support.*') ? 'active' : '' }}"
                           href="{{ route('admin.support.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-support-tickets') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-support-tickets') }}"></use></svg>
                            <span>Support Tickets</span>
                            @php $openTickets = \App\Models\SupportTicket::where('status','open')->count(); @endphp
                            @if($openTickets > 0)
                                <span class="badge badge-light-primary ms-auto">{{ $openTickets }}</span>
                            @endif
                        </a>
                    </li>

                    {{-- Error Logs --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link {{ request()->routeIs('admin.error-logs.*') ? 'active' : '' }}"
                           href="{{ route('admin.error-logs.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-error') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-error') }}"></use></svg>
                            <span>Error Logs</span>
                            @php $newErrors = \App\Models\ErrorLog::where('status','new')->count(); @endphp
                            @if($newErrors > 0)
                                <span class="badge badge-light-danger ms-auto">{{ $newErrors }}</span>
                            @endif
                        </a>
                    </li>

                    {{-- ==================== SUPER ADMIN ==================== --}}
                    @if(auth()->user()->isSuperAdmin())
                    <li class="sidebar-main-title">
                        <div><h6>Super Admin</h6></div>
                    </li>

                    {{-- Roles --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}"
                           href="{{ route('admin.roles.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-knowledgebase') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-knowledgebase') }}"></use></svg>
                            <span>Roles</span>
                        </a>
                    </li>

                    {{-- Site Settings --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}"
                           href="{{ route('admin.settings.index') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-settings') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-settings') }}"></use></svg>
                            <span>Site Settings</span>
                        </a>
                    </li>
                    @endif

                    {{-- ==================== ACCOUNT ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Account</h6></div>
                    </li>

                    {{-- Member Portal --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="{{ route('member.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-home') }}"></use></svg>
                            <span>Member Portal</span>
                        </a>
                    </li>

                </ul>
            </div>
            <div class="right-arrow" id="right-arrow"><i data-feather="arrow-right"></i></div>
        </nav>
    </div>
</div>
