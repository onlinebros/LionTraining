@extends('layouts.public')
@section('title', 'Quantum Life — Partner & Member Sponsorship Platform')
@section('body-class', 'dark-only q3-theme q3-landing')

@push('styles')
<style>
    /* ======================================================================
       Q3 landing page.

       Every colour comes from the tokens in assets/css/q3-theme.css §1 — there
       is no palette here. This is the first thing a prospect sees, so the
       restraint matters more than anywhere else: one serif heading, gold only
       on the primary action and the step numerals, no heavy gradients.

       The vendor storefront pages have their own layer (q3-storefront.css);
       this page is the marketing front door and stays self-contained.
       ====================================================================== */

    body.q3-landing { background: var(--q3-bg); color: var(--q3-text-body); }

    /* ---- Navigation ---------------------------------------------------- */
    .landing-nav {
        background: rgba(5, 5, 5, .88);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid var(--q3-border-gold-soft);
        padding: 0 40px;
        min-height: 74px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        position: sticky;
        top: 0;
        z-index: 100;
    }
    .landing-nav .logo img { height: 40px; width: auto; display: block; }
    .landing-nav .nav-links { display: flex; align-items: center; gap: 26px; }
    .landing-nav .nav-links a {
        color: var(--q3-text-muted);
        font-weight: 500;
        text-decoration: none;
        font-size: .93rem;
        transition: color var(--q3-transition);
    }
    .landing-nav .nav-links a:hover { color: var(--q3-gold-high); }
    .landing-nav .nav-links a.btn { margin-left: 4px; }

    /* ---- Hero ----------------------------------------------------------
       A single soft gold bloom behind the headline rather than a filled
       gradient panel — the ground stays black. */
    .hero {
        position: relative;
        overflow: hidden;
        padding: 110px 0 92px;
        text-align: center;
        background:
            radial-gradient(760px 380px at 50% -8%, rgba(var(--q3-gold-rgb), .13) 0%, transparent 68%),
            var(--q3-bg);
        border-bottom: 1px solid var(--q3-border);
    }
    .hero h1 {
        font-family: var(--q3-font-display);
        font-size: clamp(2.4rem, 6vw, 3.6rem);
        font-weight: 600;
        line-height: 1.12;
        letter-spacing: .004em;
        color: var(--q3-text);
        margin-bottom: 22px;
    }
    .hero h1 em { font-style: normal; color: var(--q3-gold-high); }
    .hero p {
        font-size: 1.08rem;
        color: var(--q3-text-muted);
        max-width: 560px;
        margin: 0 auto 38px;
        line-height: 1.65;
    }
    /* Unscoped by section — the same button appears in the hero and in the
       closing CTA. Qualified with `a` and the body class so it outranks
       `.q3-theme a`, which would otherwise repaint the label gold-on-gold. */
    body.q3-landing a.btn-hero {
        display: inline-block;
        margin: 6px;
        padding: 13px 34px;
        border-radius: var(--q3-radius-sm);
        font-weight: 600;
        font-size: .98rem;
        text-decoration: none;
        transition: background var(--q3-transition), box-shadow var(--q3-transition),
                    color var(--q3-transition), border-color var(--q3-transition);
        background: linear-gradient(180deg, var(--q3-gold-high) 0%, var(--q3-gold-soft) 100%);
        border: 1px solid var(--q3-gold-soft);
        color: var(--q3-gold-ink);
    }
    body.q3-landing a.btn-hero:hover {
        background: linear-gradient(180deg, #EED184 0%, var(--q3-gold) 100%);
        box-shadow: var(--q3-gold-glow);
        color: var(--q3-gold-ink);
    }
    body.q3-landing a.btn-hero-outline {
        background: transparent;
        border: 1px solid var(--q3-border-gold);
        color: var(--q3-gold-soft);
    }
    body.q3-landing a.btn-hero-outline:hover {
        background: var(--q3-gold-tint);
        border-color: var(--q3-gold);
        color: var(--q3-gold-high);
        box-shadow: none;
    }

    .hero-note {
        margin: 22px auto 0;
        font-size: .86rem;
        color: var(--q3-text-dim);
        letter-spacing: .02em;
    }

    /* ---- Sections ------------------------------------------------------- */
    .section-heading { color: var(--q3-text); font-weight: 600; letter-spacing: -.015em; }
    .section-sub { color: var(--q3-text-muted); }

    .features { padding: 88px 0; }
    .feature-card {
        background: var(--q3-surface);
        border: 1px solid var(--q3-border);
        border-radius: var(--q3-radius);
        padding: 36px 28px;
        text-align: center;
        height: 100%;
        box-shadow: none;
        transition: border-color var(--q3-transition), background var(--q3-transition);
    }
    .feature-card:hover { border-color: var(--q3-border-gold); background: var(--q3-surface-2); }
    .feature-card .icon-wrap {
        width: 60px; height: 60px;
        background: var(--q3-gold-tint-2);
        border: 1px solid var(--q3-border-gold);
        border-radius: var(--q3-radius);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 20px;
        color: var(--q3-gold);
    }
    .feature-card h5 { font-weight: 600; margin-bottom: 10px; color: var(--q3-text); }
    .feature-card p { color: var(--q3-text-muted); font-size: .93rem; margin: 0; line-height: 1.6; }

    .how-section { background: var(--q3-surface); border-block: 1px solid var(--q3-border); padding: 88px 0; }
    .how-step-num {
        font-size: 2.6rem;
        font-weight: 300;
        color: var(--q3-gold);
        font-variant-numeric: tabular-nums;
        line-height: 1;
        margin-bottom: 14px;
    }
    .how-step h5 { font-weight: 600; color: var(--q3-text); }
    .how-step p { color: var(--q3-text-muted); font-size: .93rem; }

    /* ---- Closing call to action ----------------------------------------- */
    .cta-section {
        padding: 84px 0;
        text-align: center;
        background:
            radial-gradient(620px 300px at 50% 110%, rgba(var(--q3-gold-rgb), .10) 0%, transparent 70%),
            var(--q3-bg);
    }
    .cta-section h2 { font-weight: 600; color: var(--q3-text); margin-bottom: 14px; letter-spacing: -.015em; }
    .cta-section p { color: var(--q3-text-muted); margin-bottom: 32px; }

    /* ---- Footer ---------------------------------------------------------- */
    .site-footer {
        background: var(--q3-black);
        border-top: 1px solid var(--q3-border);
        color: var(--q3-text-dim);
        padding: 30px 0;
        text-align: center;
        font-size: .85rem;
    }
    .site-footer a { color: var(--q3-text-muted); text-decoration: none; }
    .site-footer a:hover { color: var(--q3-gold-high); }

    /* ---- Mobile ---------------------------------------------------------
       The section anchors drop away; the two account actions never do. */
    @media (max-width: 767.98px) {
        .landing-nav { padding: 0 18px; gap: 12px; }
        .landing-nav .logo img { height: 32px; }
        .landing-nav .nav-links { gap: 16px; }
        .landing-nav .nav-links a.nav-anchor { display: none; }
        .hero { padding: 72px 0 60px; }
        .features, .how-section, .cta-section { padding: 60px 0; }
        .feature-card { padding: 28px 22px; }
    }
</style>
@endpush

@section('content')

{{-- Navigation --}}
<nav class="landing-nav">
    <div class="logo">
        <a href="{{ route('home') }}">
            <img src="{{ \App\Support\Asset::v('assets/images/logo/q3_logo-sm.png') }}" alt="Quantum Life">
        </a>
    </div>
    <div class="nav-links">
        <a href="#features" class="nav-anchor">Features</a>
        <a href="#how-it-works" class="nav-anchor">How it Works</a>
        {{-- Every call to action on this page asks App\Support\Registration
             rather than assuming, so the marketing copy can never invite people
             through a door the server has bolted. --}}
        @if(\App\Support\Registration::openToPublic())
            <a href="{{ route('login') }}">Log In</a>
            <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Get Started</a>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary btn-sm">Log In</a>
        @endif
    </div>
</nav>

{{-- Hero --}}
<section class="hero">
    <div class="container">
        <h1>Powering Partner<br>&amp; <em>Member Networks</em></h1>
        <p>Quantum Life connects partners and members, streamlines sponsorship management, and helps everyone grow together.</p>
        @if(\App\Support\Registration::openToPublic())
            <a href="{{ route('register') }}" class="btn-hero">Create Free Account</a>
            <a href="{{ route('login') }}" class="btn-hero btn-hero-outline">Sign In</a>
        @else
            <a href="{{ route('login') }}" class="btn-hero">Sign In</a>
            <p class="hero-note">Membership is by invitation from an existing partner.</p>
        @endif
    </div>
</section>

{{-- Features --}}
<section class="features" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-heading">Everything you need to manage sponsorships</h2>
            <p class="section-sub">Built for partners, members, and sponsors alike.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="users" style="width:26px;height:26px;"></i>
                    </div>
                    <h5>Sponsor Management</h5>
                    <p>Track all your sponsored members in one place. Manage relationships, statuses, and communications effortlessly.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="link" style="width:26px;height:26px;"></i>
                    </div>
                    <h5>Referral Links</h5>
                    <p>Share your personal referral link with new members. They sign up directly under your sponsorship with one click.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="bar-chart-2" style="width:26px;height:26px;"></i>
                    </div>
                    <h5>Progress Tracking</h5>
                    <p>Monitor member progress, milestones, and sponsorship activity with real-time dashboards and reports.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- How it Works --}}
<section class="how-section" id="how-it-works">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-heading">How it Works</h2>
        </div>
        <div class="row g-4 align-items-start">
            <div class="col-md-4 text-center how-step">
                <div class="how-step-num">01</div>
                <h5>Create your account</h5>
                <p>Sign up as a partner or member in under a minute.</p>
            </div>
            <div class="col-md-4 text-center how-step">
                <div class="how-step-num">02</div>
                <h5>Share your referral link</h5>
                <p>Partners get a unique link to invite new members directly.</p>
            </div>
            <div class="col-md-4 text-center how-step">
                <div class="how-step-num">03</div>
                <h5>Manage &amp; grow</h5>
                <p>Track sponsorships, update statuses, and grow your network.</p>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="cta-section">
    <div class="container">
        @if(\App\Support\Registration::openToPublic())
            <h2>Ready to get started?</h2>
            <p>Join Quantum Life and take your sponsorship program to the next level.</p>
            <a href="{{ route('register') }}" class="btn-hero">Create Free Account</a>
        @else
            <h2>Joining Quantum Life</h2>
            <p>We grow by sponsorship. Ask the partner who told you about us for their
               referral link — it enrols you directly under them.</p>
            <a href="{{ route('login') }}" class="btn-hero">Sign In</a>
        @endif
    </div>
</section>

{{-- Footer --}}
<footer class="site-footer">
    <div class="container">
        <p class="mb-0">&copy; {{ date('Y') }} Quantum Life &mdash; All rights reserved.
            <a href="{{ route('login') }}" class="ms-3">Log In</a>
            <a href="{{ route('admin.auth.login') }}" class="ms-3">Admin</a>
        </p>
    </div>
</footer>

@endsection
