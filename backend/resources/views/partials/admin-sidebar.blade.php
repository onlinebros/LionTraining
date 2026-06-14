<div class="sidebar-wrapper" data-sidebar-layout="stroke-svg">
    <div>
        <div class="logo-wrapper">
            <a href="{{ route('admin.dashboard') }}">
                <img class="img-fluid for-light" src="{{ asset('assets/images/logo/logo.png') }}" alt="Lion Training">
                <img class="img-fluid for-dark" src="{{ asset('assets/images/logo/logo_dark.png') }}" alt="Lion Training">
            </a>
            <div class="back-btn"><i class="fa-solid fa-angle-left"></i></div>
            <div class="toggle-sidebar"><i class="status_toggle middle sidebar-toggle" data-feather="grid"></i></div>
        </div>
        <div class="logo-icon-wrapper">
            <a href="{{ route('admin.dashboard') }}">
                <img class="img-fluid" src="{{ asset('assets/images/logo/logo-icon.png') }}" alt="">
            </a>
        </div>
        <nav class="sidebar-main">
            <div class="left-arrow" id="left-arrow"><i data-feather="arrow-left"></i></div>
            <div id="sidebar-menu">
                <ul class="sidebar-links" id="simple-bar">
                    <li class="back-btn">
                        <a href="{{ route('admin.dashboard') }}">
                            <img class="img-fluid" src="{{ asset('assets/images/logo/logo-icon.png') }}" alt="">
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
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-home') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-home') }}"></use></svg>
                            <span>Dashboard</span>
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

                    {{-- ==================== APPLICATIONS ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Applications</h6></div>
                    </li>

                    {{-- Projects --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.projects.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-project') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-project') }}"></use></svg>
                            <span>Projects</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Project List</a></li>
                            <li><a href="#">Project Details</a></li>
                            <li><a href="#">Create New</a></li>
                        </ul>
                    </li>

                    {{-- File Manager --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-file') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-file') }}"></use></svg>
                            <span>File Manager</span>
                        </a>
                    </li>

                    {{-- Kanban Board --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-board') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-board') }}"></use></svg>
                            <span>Kanban Board</span>
                        </a>
                    </li>

                    {{-- Ecommerce --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ecommerce') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ecommerce') }}"></use></svg>
                            <span>Ecommerce</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Add Product</a></li>
                            <li><a href="#">Products Grid</a></li>
                            <li><a href="#">Products List</a></li>
                            <li><a href="#">Product Details</a></li>
                            <li><a href="#">Category</a></li>
                            <li><a href="#">Order History</a></li>
                            <li><a href="#">Invoice</a></li>
                            <li><a href="#">Cart</a></li>
                            <li><a href="#">Checkout</a></li>
                        </ul>
                    </li>

                    {{-- Mail Box --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-email') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-email') }}"></use></svg>
                            <span>Mail Box</span>
                        </a>
                    </li>

                    {{-- Chat --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-chat') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-chat') }}"></use></svg>
                            <span>Chat</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Private Chat</a></li>
                            <li><a href="#">Group Chat</a></li>
                        </ul>
                    </li>

                    {{-- Reports --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-charts') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-charts') }}"></use></svg>
                            <span>Reports</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Sales Report</a></li>
                            <li><a href="#">Customer Orders</a></li>
                        </ul>
                    </li>

                    {{-- Bookmarks --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-star') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-star') }}"></use></svg>
                            <span>Bookmarks</span>
                        </a>
                    </li>

                    {{-- Contacts --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-contact') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-contact') }}"></use></svg>
                            <span>Contacts</span>
                        </a>
                    </li>

                    {{-- Tasks --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-task') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-task') }}"></use></svg>
                            <span>Tasks</span>
                        </a>
                    </li>

                    {{-- Calendar --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-calendar') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-calendar') }}"></use></svg>
                            <span>Calendar</span>
                        </a>
                    </li>

                    {{-- ==================== FORMS & TABLES ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Forms &amp; Tables</h6></div>
                    </li>

                    {{-- Forms --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-form') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-form') }}"></use></svg>
                            <span>Forms</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Form Validation</a></li>
                            <li><a href="#">Base Inputs</a></li>
                            <li><a href="#">Checkbox &amp; Radio</a></li>
                            <li><a href="#">Input Groups</a></li>
                            <li><a href="#">Input Mask</a></li>
                            <li><a href="#">Datepicker</a></li>
                            <li><a href="#">Select2</a></li>
                            <li><a href="#">Switch</a></li>
                            <li><a href="#">Form Wizard</a></li>
                        </ul>
                    </li>

                    {{-- Tables --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-table') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-table') }}"></use></svg>
                            <span>Tables</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Bootstrap Basic</a></li>
                            <li><a href="#">Data Tables</a></li>
                        </ul>
                    </li>

                    {{-- ==================== COMPONENTS ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Components</h6></div>
                    </li>

                    {{-- UI Kits --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-ui-kits') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-ui-kits') }}"></use></svg>
                            <span>UI Kits</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Typography</a></li>
                            <li><a href="#">Avatars</a></li>
                            <li><a href="#">Tags &amp; Pills</a></li>
                            <li><a href="#">Progress</a></li>
                            <li><a href="#">Modal</a></li>
                            <li><a href="#">Alert</a></li>
                            <li><a href="#">Dropdown</a></li>
                            <li><a href="#">Accordion</a></li>
                            <li><a href="#">Tabs</a></li>
                            <li><a href="#">Lists</a></li>
                        </ul>
                    </li>

                    {{-- Bonus UI --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-bonus-kit') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-bonus-kit') }}"></use></svg>
                            <span>Bonus UI</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Scrollable</a></li>
                            <li><a href="#">Toasts</a></li>
                            <li><a href="#">Rating</a></li>
                            <li><a href="#">Sweet Alert2</a></li>
                            <li><a href="#">Ribbons</a></li>
                            <li><a href="#">Pagination</a></li>
                            <li><a href="#">Timeline</a></li>
                            <li><a href="#">Basic Card</a></li>
                        </ul>
                    </li>

                    {{-- Animations --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-animation') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-animation') }}"></use></svg>
                            <span>Animations</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Animate</a></li>
                            <li><a href="#">AOS Animation</a></li>
                            <li><a href="#">Wow Animation</a></li>
                        </ul>
                    </li>

                    {{-- Icons --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-icons') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-icons') }}"></use></svg>
                            <span>Icons</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Flag Icon</a></li>
                            <li><a href="#">Font Awesome</a></li>
                            <li><a href="#">Ico Icon</a></li>
                            <li><a href="#">Themify Icon</a></li>
                            <li><a href="#">Feather Icon</a></li>
                        </ul>
                    </li>

                    {{-- Buttons --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-button') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-button') }}"></use></svg>
                            <span>Buttons</span>
                        </a>
                    </li>

                    {{-- Charts --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-charts') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-charts') }}"></use></svg>
                            <span>Charts</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Apex Charts</a></li>
                            <li><a href="#">Google Charts</a></li>
                            <li><a href="#">Flot Charts</a></li>
                        </ul>
                    </li>

                    {{-- ==================== PAGES ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Pages</h6></div>
                    </li>

                    {{-- Authentication --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-authentication') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-authentication') }}"></use></svg>
                            <span>Authentication</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="{{ route('admin.auth.login') }}">Login</a></li>
                            <li><a href="#">Register</a></li>
                            <li><a href="#">Forgot Password</a></li>
                        </ul>
                    </li>

                    {{-- Error Pages --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-error') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-error') }}"></use></svg>
                            <span>Error Pages</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Error 403</a></li>
                            <li><a href="#">Error 404</a></li>
                            <li><a href="#">Error 500</a></li>
                        </ul>
                    </li>

                    {{-- FAQ --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-faq') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-faq') }}"></use></svg>
                            <span>FAQ</span>
                        </a>
                    </li>

                    {{-- Pricing --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-pricing') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-pricing') }}"></use></svg>
                            <span>Pricing</span>
                        </a>
                    </li>

                    {{-- ==================== MISCELLANEOUS ==================== --}}
                    <li class="sidebar-main-title">
                        <div><h6>Miscellaneous</h6></div>
                    </li>

                    {{-- Gallery --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-gallery') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-gallery') }}"></use></svg>
                            <span>Gallery</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Gallery Grid</a></li>
                            <li><a href="#">Masonry Gallery</a></li>
                            <li><a href="#">Hover Effects</a></li>
                        </ul>
                    </li>

                    {{-- Blog --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-blog') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-blog') }}"></use></svg>
                            <span>Blog</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Blog List</a></li>
                            <li><a href="#">Blog Details</a></li>
                            <li><a href="#">Add Blog</a></li>
                        </ul>
                    </li>

                    {{-- Jobs --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-job-search') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-job-search') }}"></use></svg>
                            <span>Jobs</span>
                        </a>
                    </li>

                    {{-- Learning --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-learning') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-learning') }}"></use></svg>
                            <span>Learning</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Course List</a></li>
                            <li><a href="#">Course Details</a></li>
                        </ul>
                    </li>

                    {{-- Maps --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-maps') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-maps') }}"></use></svg>
                            <span>Maps</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Google Maps</a></li>
                            <li><a href="#">Leaflet Maps</a></li>
                        </ul>
                    </li>

                    {{-- Editors --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-editors') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-editors') }}"></use></svg>
                            <span>Editors</span>
                        </a>
                        <ul class="sidebar-submenu">
                            <li><a href="#">Summernote</a></li>
                            <li><a href="#">CKEditor</a></li>
                            <li><a href="#">ACE Code Editor</a></li>
                        </ul>
                    </li>

                    {{-- Knowledgebase --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-knowledgebase') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-knowledgebase') }}"></use></svg>
                            <span>Knowledgebase</span>
                        </a>
                    </li>

                    {{-- Support Ticket --}}
                    <li class="sidebar-list">
                        <i class="fa-solid fa-thumbtack"></i>
                        <a class="sidebar-link sidebar-title link-nav" href="#">
                            <svg class="stroke-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#stroke-support-tickets') }}"></use></svg>
                            <svg class="fill-icon"><use href="{{ asset('assets/svg/icon-sprite.svg#fill-support-tickets') }}"></use></svg>
                            <span>Support Ticket</span>
                        </a>
                    </li>

                </ul>
            </div>
            <div class="right-arrow" id="right-arrow"><i data-feather="arrow-right"></i></div>
        </nav>
    </div>
</div>
