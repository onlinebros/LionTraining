<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Your proposal') · Quantum 3 Solution</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Public+Sans:wght@400;500;600&display=swap">
    <style>
        /* A proposal you read: a dark Q3 band, then a light page with gold used sparingly. */
        :root {
            --ink: #17181a; --ink-soft: #4a4c50; --ink-dim: #77797d;
            --paper: #f6f6f3; --sheet: #ffffff; --rule: #e3e2dc;
            --band: #0b0b0c; --band-text: #f5f1e8; --gold: #b8922a; --gold-bright: #d4af37; --gold-tint: #f6efda;
            --ok: #2f7d57; --ok-tint: #e3f2ea; --warn-tint: #fbf1dc;
            --display: "Newsreader", Georgia, "Times New Roman", serif;
            --body: "Public Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--ink); font: 16px/1.6 var(--body); }
        a { color: var(--gold); }
        .band { background: var(--band); color: var(--band-text); }
        .wrap { max-width: 920px; margin: 0 auto; padding-left: max(20px, env(safe-area-inset-left)); padding-right: max(20px, env(safe-area-inset-right)); }
        .band .wrap { padding-block: 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .brand { display: flex; align-items: center; gap: 10px; color: var(--band-text); text-decoration: none; font-weight: 600; letter-spacing: .02em; }
        .brand img { width: 30px; height: 30px; }
        .band small { color: #a9a6a0; font-size: 13px; }
        h1, h2, h3 { font-family: var(--display); font-weight: 600; line-height: 1.15; text-wrap: balance; margin: 0; }
        h1 { font-size: clamp(30px, 5vw, 46px); }
        h2 { font-size: 26px; margin-bottom: 14px; }
        h3 { font-size: 19px; }
        p { margin: 0 0 12px; }
        .eyebrow { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: var(--gold); font-weight: 600; }
        .lede { font-size: 18px; color: var(--ink-soft); max-width: 62ch; }
        .muted { color: var(--ink-dim); font-size: 14px; }
        .sheet { background: var(--sheet); border: 1px solid var(--rule); border-radius: 10px; padding: clamp(20px, 4vw, 36px); }
        section { padding-block: 28px; scroll-margin-top: 70px; }
        .stack { display: grid; gap: 16px; }
        .md > :last-child { margin-bottom: 0; } .md ul { padding-left: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-dim); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--rule); }
        td { padding: 12px 10px; border-bottom: 1px solid var(--rule); vertical-align: top; }
        .table-scroll { overflow-x: auto; }
        .qty { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
        .claims { display: grid; gap: 14px; }
        .claim { border-left: 3px solid var(--gold-bright); padding: 4px 0 4px 18px; }
        .claim blockquote { margin: 0; font-family: var(--display); font-size: 19px; line-height: 1.4; }
        .claim .fn { font-size: 13px; color: var(--ink-dim); margin-top: 6px; }
        .benefits { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .benefit { background: var(--paper); border-radius: 8px; padding: 16px 18px; }
        .note { background: var(--warn-tint); border-radius: 8px; padding: 12px 16px; font-size: 14px; }
        .flash { border-radius: 8px; padding: 12px 16px; margin-top: 20px; font-size: 15px; }
        .flash.ok { background: var(--ok-tint); color: var(--ok); } .flash.err { background: #fbe5e3; color: #9c2b22; }
        .nav { position: sticky; top: 0; z-index: 5; background: rgba(246,246,243,.94); backdrop-filter: blur(6px); border-bottom: 1px solid var(--rule); }
        .nav .wrap { display: flex; gap: 18px; overflow-x: auto; padding-block: 12px; font-size: 14px; white-space: nowrap; }
        .nav a { color: var(--ink-soft); text-decoration: none; } .nav a:hover, .nav a:focus-visible { color: var(--ink); }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        input[type=text], input[type=email], textarea, select { width: 100%; font: inherit; font-size: 15px; padding: 10px 12px; border: 1px solid #cfcdc5; border-radius: 6px; background: #fff; color: var(--ink); }
        input:focus, textarea:focus, select:focus, button:focus-visible { outline: 2px solid var(--gold-bright); outline-offset: 1px; }
        .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .btn { display: inline-block; font: inherit; font-weight: 600; font-size: 15px; padding: 11px 20px; border-radius: 6px; border: 0; background: var(--band); color: var(--band-text); cursor: pointer; text-decoration: none; }
        .btn.gold { background: var(--gold-bright); color: var(--band); }
        .btn.ghost { background: transparent; color: var(--ink); border: 1px solid #cfcdc5; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .qa { border-top: 1px solid var(--rule); padding-top: 14px; }
        .qa .q { font-weight: 600; } .qa .a { margin-top: 6px; }
        .tag { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--gold-tint); color: #6e5414; }
        footer { padding-block: 40px; font-size: 13px; color: var(--ink-dim); }
        details summary { cursor: pointer; font-weight: 600; }
        @media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; } }
    </style>
</head>
<body>
    <header class="band">
        <div class="wrap">
            <span class="brand"><img src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="">Quantum 3 Solution</span>
            @yield('band-right')
        </div>
    </header>
    @yield('content')
</body>
</html>
