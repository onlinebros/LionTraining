@extends('layouts.public')
@section('title', 'Lion Training — Partner & Member Sponsorship Platform')

@push('styles')
<style>
    body { background: #f8f8f8; }
    .landing-nav { background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.07); padding: 0 40px; height: 70px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
    .landing-nav .logo img { height: 40px; }
    .landing-nav .nav-links a { margin-left: 24px; color: #4a5568; font-weight: 500; text-decoration: none; font-size: .95rem; }
    .landing-nav .nav-links a:hover { color: #7366ff; }
    .hero { background: linear-gradient(135deg, #7366ff 0%, #563dd9 100%); color: #fff; padding: 100px 0 80px; text-align: center; }
    .hero h1 { font-size: 3rem; font-weight: 700; margin-bottom: 20px; line-height: 1.2; }
    .hero p { font-size: 1.2rem; opacity: .88; max-width: 580px; margin: 0 auto 36px; }
    .hero .btn-hero { background: #fff; color: #7366ff; font-weight: 700; padding: 14px 36px; border-radius: 8px; font-size: 1rem; margin: 6px; text-decoration: none; display: inline-block; transition: transform .15s; }
    .hero .btn-hero:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.15); }
    .hero .btn-hero-outline { background: transparent; color: #fff; border: 2px solid rgba(255,255,255,.7); }
    .hero .btn-hero-outline:hover { background: rgba(255,255,255,.1); }
    .features { padding: 80px 0; }
    .feature-card { background: #fff; border-radius: 12px; padding: 36px 28px; text-align: center; box-shadow: 0 2px 16px rgba(0,0,0,.06); height: 100%; }
    .feature-card .icon-wrap { width: 64px; height: 64px; background: #f0eeff; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: #7366ff; }
    .feature-card h5 { font-weight: 700; margin-bottom: 10px; }
    .feature-card p { color: #718096; font-size: .95rem; margin: 0; }
    .cta-section { background: linear-gradient(135deg, #563dd9 0%, #7366ff 100%); color: #fff; padding: 70px 0; text-align: center; }
    .cta-section h2 { font-weight: 700; margin-bottom: 14px; }
    .cta-section p { opacity: .88; margin-bottom: 32px; }
    .site-footer { background: #1a202c; color: #a0aec0; padding: 30px 0; text-align: center; font-size: .875rem; }
    .site-footer a { color: #7366ff; text-decoration: none; }
</style>
@endpush

@section('content')

{{-- Navigation --}}
<nav class="landing-nav">
    <div class="logo">
        <a href="{{ route('home') }}">
            <img src="{{ asset('assets/images/logo/logo.png') }}" alt="Lion Training" class="for-light" style="height:40px;">
        </a>
    </div>
    <div class="nav-links">
        <a href="#features">Features</a>
        <a href="#how-it-works">How it Works</a>
        <a href="{{ route('login') }}">Log In</a>
        <a href="{{ route('register') }}" class="btn btn-primary btn-sm ms-2">Get Started</a>
    </div>
</nav>

{{-- Hero --}}
<section class="hero">
    <div class="container">
        <h1>Powering Partner<br>& Member Networks</h1>
        <p>Lion Training connects partners and members, streamlines sponsorship management, and helps everyone grow together.</p>
        <a href="{{ route('register') }}" class="btn-hero">Create Free Account</a>
        <a href="{{ route('login') }}" class="btn-hero btn-hero-outline">Sign In</a>
    </div>
</section>

{{-- Features --}}
<section class="features" id="features">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Everything you need to manage sponsorships</h2>
            <p class="text-muted">Built for partners, members, and sponsors alike.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="users" style="width:28px;height:28px;"></i>
                    </div>
                    <h5>Sponsor Management</h5>
                    <p>Track all your sponsored members in one place. Manage relationships, statuses, and communications effortlessly.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="link" style="width:28px;height:28px;"></i>
                    </div>
                    <h5>Referral Links</h5>
                    <p>Share your personal referral link with new members. They sign up directly under your sponsorship with one click.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="icon-wrap">
                        <i data-feather="bar-chart-2" style="width:28px;height:28px;"></i>
                    </div>
                    <h5>Progress Tracking</h5>
                    <p>Monitor member progress, milestones, and sponsorship activity with real-time dashboards and reports.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- How it Works --}}
<section style="background:#fff; padding:80px 0;" id="how-it-works">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">How it Works</h2>
        </div>
        <div class="row g-4 align-items-center">
            <div class="col-md-4 text-center">
                <div style="font-size:3rem;font-weight:700;color:#7366ff;margin-bottom:12px;">1</div>
                <h5 class="fw-bold">Create your account</h5>
                <p class="text-muted">Sign up as a partner or member in under a minute.</p>
            </div>
            <div class="col-md-4 text-center">
                <div style="font-size:3rem;font-weight:700;color:#7366ff;margin-bottom:12px;">2</div>
                <h5 class="fw-bold">Share your referral link</h5>
                <p class="text-muted">Partners get a unique link to invite new members directly.</p>
            </div>
            <div class="col-md-4 text-center">
                <div style="font-size:3rem;font-weight:700;color:#7366ff;margin-bottom:12px;">3</div>
                <h5 class="fw-bold">Manage & grow</h5>
                <p class="text-muted">Track sponsorships, update statuses, and grow your network.</p>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="cta-section">
    <div class="container">
        <h2>Ready to get started?</h2>
        <p>Join Lion Training and take your sponsorship program to the next level.</p>
        <a href="{{ route('register') }}" class="btn btn-light btn-lg fw-bold px-5">Create Free Account</a>
    </div>
</section>

{{-- Footer --}}
<footer class="site-footer">
    <div class="container">
        <p class="mb-0">&copy; {{ date('Y') }} Lion Training &mdash; All rights reserved.
            <a href="{{ route('login') }}" class="ms-3">Log In</a>
            <a href="{{ route('admin.auth.login') }}" class="ms-3">Admin</a>
        </p>
    </div>
</footer>

@endsection
