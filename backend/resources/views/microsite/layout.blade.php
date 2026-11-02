{{-- A business account's private microsite. Three themes (BusinessAccount::THEMES),
     tinted by the client's colour. The portal's variables are mapped per theme
     so an embedded proposal (portal._proposal) matches. --}}
@php
    $theme = $account->theme();
    $accent = $account->accent();
    $accentInk = $account->brand_color ? $account->brandInk() : '#17181a';
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $account->name }}</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Newsreader:opsz,wght@6..72,500;6..72,600&display=swap">
    <style>
        :root {
            --accent: {{ $accent }}; --accent-ink: {{ $accentInk }};
            --gold: #d4af37;
            --sans: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
            --serif: "Newsreader", Georgia, serif;
            --radius: 18px; --max: 1120px;
            --ease: cubic-bezier(.2, .7, .2, 1);
        }
        [data-theme="aurora"] {
            --bg: #07080c; --bg-2: #0d0f15; --surface: rgba(255,255,255,.045); --surface-2: rgba(255,255,255,.07);
            --text: #eef0f4; --text-soft: #b9bfca; --text-dim: #8a91a0; --rule: rgba(255,255,255,.09);
            --glow: color-mix(in srgb, var(--accent) 55%, transparent); --display: var(--sans); --display-weight: 800;
            --shadow: 0 30px 80px -30px rgba(0,0,0,.7);
        }
        [data-theme="daylight"] {
            --bg: #f6f7fb; --bg-2: #ffffff; --surface: #ffffff; --surface-2: #f1f3f8;
            --text: #0e1116; --text-soft: #444b57; --text-dim: #6b7280; --rule: #e4e7ee;
            --glow: color-mix(in srgb, var(--accent) 30%, transparent); --display: var(--sans); --display-weight: 800;
            --shadow: 0 24px 60px -28px rgba(20,30,60,.25);
        }
        [data-theme="editorial"] {
            --bg: #f6f5f0; --bg-2: #fffefb; --surface: #ffffff; --surface-2: #f1efe7;
            --text: #17181a; --text-soft: #4a4c50; --text-dim: #77797d; --rule: #e3e2dc;
            --glow: color-mix(in srgb, var(--accent) 22%, transparent); --display: var(--serif); --display-weight: 600;
            --shadow: 0 20px 50px -30px rgba(40,30,10,.25);
        }
        /* What the embedded proposal reads. */
        :root { --ink: var(--text); --ink-soft: var(--text-soft); --ink-dim: var(--text-dim); --paper: var(--surface-2); --sheet: var(--surface); --gold-bright: var(--accent); --gold-tint: color-mix(in srgb, var(--accent) 16%, transparent); --warn-tint: color-mix(in srgb, var(--gold) 14%, transparent); --body: var(--sans); --band: var(--text); --band-text: var(--bg); }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 17px/1.65 var(--sans); -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        a { color: inherit; }
        img { max-width: 100%; }
        h1, h2, h3 { font-family: var(--display); font-weight: var(--display-weight); line-height: 1.08; letter-spacing: -.02em; text-wrap: balance; margin: 0; }
        [data-theme="editorial"] h1, [data-theme="editorial"] h2, [data-theme="editorial"] h3 { letter-spacing: -.01em; }
        p { margin: 0 0 14px; }
        .wrap { max-width: var(--max); margin: 0 auto; padding-inline: max(20px, env(safe-area-inset-left)) max(20px, env(safe-area-inset-right)); }
        .muted { color: var(--text-dim); font-size: 14px; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--text-soft); }
        .eyebrow::before { content: ""; width: 22px; height: 2px; border-radius: 2px; background: var(--accent); }

        /* Top bar */
        .progress { position: fixed; inset: 0 0 auto 0; height: 3px; z-index: 60; background: linear-gradient(90deg, var(--accent), var(--gold)); transform-origin: 0 50%; transform: scaleX(var(--p, 0)); }
        .top { position: sticky; top: 0; z-index: 50; backdrop-filter: saturate(160%) blur(14px); -webkit-backdrop-filter: saturate(160%) blur(14px); background: color-mix(in srgb, var(--bg) 72%, transparent); border-bottom: 1px solid var(--rule); }
        .top .wrap { display: flex; align-items: center; gap: 18px; min-height: 64px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; font-weight: 700; flex: none; white-space: nowrap; }
        .brand .client-logo { height: 34px; max-width: 150px; object-fit: contain; border-radius: 8px; background: #fff; padding: 3px 7px; }
        .brand .x { color: var(--text-dim); font-weight: 400; }
        .brand .q3 { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--text-soft); font-weight: 600; white-space: nowrap; }
        .brand .q3 img { width: 22px; height: 22px; }
        .top nav { margin-left: auto; min-width: 0; flex: 0 1 auto; display: flex; gap: 4px; overflow-x: auto; scrollbar-width: none; mask-image: linear-gradient(90deg, transparent, #000 16px, #000 calc(100% - 16px), transparent); -webkit-mask-image: linear-gradient(90deg, transparent, #000 16px, #000 calc(100% - 16px), transparent); }
        .top nav::-webkit-scrollbar { display: none; }
        .top nav a { text-decoration: none; font-size: 14px; color: var(--text-soft); padding: 7px 12px; border-radius: 999px; white-space: nowrap; transition: background .2s, color .2s; }
        .top nav a:hover, .top nav a.on { background: var(--surface-2); color: var(--text); }
        .who { font-size: 13px; color: var(--text-dim); white-space: nowrap; }
        .who button { background: none; border: 0; color: inherit; text-decoration: underline; font: inherit; cursor: pointer; padding: 0; }
        @media (max-width: 1100px) { .top nav { display: none; } .who { margin-left: auto; } }
        @media (max-width: 560px) { .brand > span:first-child { max-width: 52vw; overflow: hidden; text-overflow: ellipsis; } .who { white-space: normal; text-align: right; } }
        @media (max-width: 560px) { .brand .q3 span, .brand .x { display: none; } }

        /* Hero */
        .hero { position: relative; min-height: min(92vh, 900px); display: grid; align-items: center; overflow: hidden; isolation: isolate; padding-block: 120px 90px; }
        .hero .orb { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .85; z-index: -1; will-change: transform; }
        .hero .o1 { width: 52vw; height: 52vw; max-width: 760px; max-height: 760px; background: var(--glow); top: -18%; right: -12%; animation: drift1 18s var(--ease) infinite alternate; }
        .hero .o2 { width: 38vw; height: 38vw; max-width: 520px; max-height: 520px; background: color-mix(in srgb, var(--gold) 28%, transparent); bottom: -20%; left: -10%; animation: drift2 22s var(--ease) infinite alternate; }
        .hero .o3 { width: 26vw; height: 26vw; max-width: 380px; max-height: 380px; background: color-mix(in srgb, var(--accent) 35%, #3b82f6 20%); top: 30%; left: 35%; opacity: .35; animation: drift1 26s var(--ease) infinite alternate-reverse; }
        .hero .grid-bg { position: absolute; inset: 0; z-index: -1; background-image: linear-gradient(var(--rule) 1px, transparent 1px), linear-gradient(90deg, var(--rule) 1px, transparent 1px); background-size: 56px 56px; mask-image: radial-gradient(ellipse at 50% 40%, #000 20%, transparent 75%); -webkit-mask-image: radial-gradient(ellipse at 50% 40%, #000 20%, transparent 75%); }
        @keyframes drift1 { to { transform: translate(-6%, 8%) scale(1.08); } }
        @keyframes drift2 { to { transform: translate(10%, -6%) scale(1.12); } }
        .hero h1 { font-size: clamp(40px, 7.4vw, 92px); max-width: 15ch; margin: 20px 0 22px; }
        .hero h1 .grad { background: linear-gradient(100deg, var(--text) 10%, var(--accent) 55%, var(--gold) 95%); -webkit-background-clip: text; background-clip: text; color: transparent; }
        [data-theme="editorial"] .hero h1 .grad { background: none; color: var(--text); }
        .hero .lead { font-size: clamp(18px, 2vw, 22px); color: var(--text-soft); max-width: 58ch; }
        .chips { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 30px; }
        .chip { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px; background: var(--surface); border: 1px solid var(--rule); font-size: 14px; font-weight: 600; backdrop-filter: blur(8px); }
        .chip i { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 12px var(--accent); }
        .meta { margin-top: 44px; display: flex; gap: 22px; flex-wrap: wrap; font-size: 13px; color: var(--text-dim); }
        .cue { position: absolute; left: 50%; bottom: 26px; transform: translateX(-50%); width: 26px; height: 42px; border-radius: 14px; border: 2px solid var(--rule); }
        .cue::after { content: ""; position: absolute; left: 50%; top: 8px; width: 4px; height: 8px; margin-left: -2px; border-radius: 2px; background: var(--text-soft); animation: cue 1.8s infinite; }
        @keyframes cue { 0% { opacity: 0; transform: translateY(0); } 40% { opacity: 1; } 100% { opacity: 0; transform: translateY(12px); } }

        /* Sections */
        .sec { padding-block: clamp(64px, 10vw, 120px); position: relative; scroll-margin-top: 64px; }
        .sec + .sec { border-top: 1px solid var(--rule); }
        .sec-head { max-width: 760px; margin-bottom: 44px; }
        .sec-head h2 { font-size: clamp(32px, 4.6vw, 54px); margin: 14px 0 16px; }
        .sec-head .lead { font-size: clamp(17px, 1.6vw, 20px); color: var(--text-soft); margin: 0; }
        .num-label { font-variant-numeric: tabular-nums; }
        .tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: color-mix(in srgb, var(--gold) 18%, transparent); color: var(--text); margin: 0 6px 10px 0; }

        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 18px; }
        .card { position: relative; padding: 28px; border-radius: var(--radius); background: var(--surface); border: 1px solid var(--rule); overflow: hidden; transition: transform .45s var(--ease), border-color .3s, box-shadow .45s var(--ease); box-shadow: var(--shadow); }
        .card::before { content: ""; position: absolute; inset: 0; background: radial-gradient(420px circle at var(--mx, -999px) var(--my, -999px), color-mix(in srgb, var(--accent) 16%, transparent), transparent 45%); pointer-events: none; }
        .card:hover { transform: translateY(-4px); border-color: color-mix(in srgb, var(--accent) 45%, var(--rule)); }
        .card h3 { font-family: var(--sans); font-weight: 700; font-size: 20px; letter-spacing: -.01em; margin: 18px 0 8px; }
        .card p { color: var(--text-soft); margin: 0; font-size: 16px; }
        .ico { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; color: var(--accent); background: color-mix(in srgb, var(--accent) 14%, transparent); border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent); }
        [data-theme="daylight"] .ico, [data-theme="editorial"] .ico { color: color-mix(in srgb, var(--accent) 80%, #000); }
        .ico svg { width: 24px; height: 24px; }

        .timeline { position: relative; display: grid; gap: 26px; padding-left: 64px; max-width: 820px; }
        .timeline::before { content: ""; position: absolute; left: 21px; top: 8px; bottom: 8px; width: 2px; background: var(--rule); }
        .timeline::after { content: ""; position: absolute; left: 21px; top: 8px; width: 2px; height: calc((100% - 16px) * var(--fill, 0)); background: linear-gradient(var(--accent), var(--gold)); transition: height .2s linear; }
        .step { position: relative; }
        .step .dot { position: absolute; left: -64px; top: 0; width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 15px; background: var(--bg-2); border: 2px solid var(--rule); z-index: 1; transition: border-color .4s, background .4s, color .4s; }
        .step.in .dot { border-color: var(--accent); background: var(--accent); color: var(--accent-ink); }
        .step h3 { font-family: var(--sans); font-weight: 700; font-size: 21px; margin: 6px 0 6px; }
        .step .when { font-size: 13px; font-weight: 700; color: var(--accent); letter-spacing: .04em; text-transform: uppercase; }
        [data-theme="daylight"] .step .when, [data-theme="editorial"] .step .when { color: color-mix(in srgb, var(--accent) 75%, #000); }
        .step p { color: var(--text-soft); margin: 0; }

        .proof { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 18px; }
        .quote { position: relative; padding: 30px; border-radius: var(--radius); background: var(--surface); border: 1px solid var(--rule); box-shadow: var(--shadow); overflow: hidden; }
        .quote::after { content: ""; position: absolute; inset: auto -40% -60% auto; width: 80%; height: 80%; background: radial-gradient(circle, var(--glow), transparent 65%); opacity: .5; pointer-events: none; }
        .quote.feature { grid-column: 1 / -1; padding: clamp(30px, 5vw, 56px); }
        .quote blockquote { margin: 0; font-size: 19px; line-height: 1.5; font-weight: 500; position: relative; z-index: 1; }
        .quote.feature blockquote { font-family: var(--display); font-weight: var(--display-weight); font-size: clamp(24px, 3.2vw, 38px); line-height: 1.22; letter-spacing: -.015em; }
        .quote .n { color: var(--accent); font-variant-numeric: tabular-nums; font-weight: 800; }
        [data-theme="daylight"] .quote .n, [data-theme="editorial"] .quote .n { color: color-mix(in srgb, var(--accent) 80%, #000); }
        .quote .fn { position: relative; z-index: 1; margin-top: 16px; font-size: 13px; color: var(--text-dim); }
        .attrib { margin-top: 22px; font-size: 14px; color: var(--text-dim); }

        .faq { display: grid; gap: 12px; max-width: 860px; }
        .faq details { border-radius: 14px; background: var(--surface); border: 1px solid var(--rule); transition: border-color .3s; }
        .faq details[open] { border-color: color-mix(in srgb, var(--accent) 45%, var(--rule)); }
        .faq summary { list-style: none; cursor: pointer; padding: 20px 56px 20px 24px; font-weight: 700; font-size: 18px; position: relative; }
        .faq summary::-webkit-details-marker { display: none; }
        .faq summary::after { content: "+"; position: absolute; right: 22px; top: 50%; transform: translateY(-50%); font-size: 26px; font-weight: 400; color: var(--accent); transition: transform .3s var(--ease); }
        .faq details[open] summary::after { transform: translateY(-50%) rotate(45deg); }
        .faq .a { padding: 0 24px 22px; color: var(--text-soft); }

        .plan { display: grid; grid-template-columns: 220px 1fr; gap: 40px; align-items: start; }
        @media (max-width: 760px) { .plan { grid-template-columns: 1fr; } }
        .ring { position: sticky; top: 100px; display: grid; place-items: center; text-align: center; }
        .ring svg { width: 180px; height: 180px; transform: rotate(-90deg); }
        .ring circle { fill: none; stroke-width: 10; }
        .ring .track { stroke: var(--rule); }
        .ring .bar { stroke: url(#ringGrad); stroke-linecap: round; transition: stroke-dashoffset 1.2s var(--ease); }
        .ring .label { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -62%); }
        .ring .label b { display: block; font-size: 40px; font-weight: 800; line-height: 1; }
        .ring .label span { font-size: 13px; color: var(--text-dim); }
        .steps { display: grid; gap: 10px; }
        .pstep { display: flex; align-items: center; gap: 16px; padding: 16px 18px; border-radius: 14px; background: var(--surface); border: 1px solid var(--rule); }
        .pstep .tick { flex: none; width: 30px; height: 30px; border-radius: 50%; border: 2px solid var(--rule); display: grid; place-items: center; background: transparent; color: transparent; cursor: default; padding: 0; transition: all .3s var(--ease); }
        .pstep .tick svg { width: 16px; height: 16px; }
        .pstep button.tick { cursor: pointer; }
        .pstep button.tick:hover { border-color: var(--accent); }
        .pstep.done .tick { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        .pstep.done .t { text-decoration: line-through; text-decoration-color: var(--text-dim); color: var(--text-dim); }
        .pstep .t { flex: 1; font-weight: 600; }
        .pstep .w { font-size: 13px; color: var(--text-dim); white-space: nowrap; }
        .owner { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--surface-2); color: var(--text-soft); white-space: nowrap; }
        .owner.you { background: color-mix(in srgb, var(--accent) 18%, transparent); color: var(--text); }
        @media (max-width: 560px) { .pstep { flex-wrap: wrap; } .pstep .t { flex-basis: calc(100% - 50px); } .pstep .w, .pstep .owner { margin-left: 46px; } .pstep .owner + .w { margin-left: 0; } }

        .cta { position: relative; overflow: hidden; border-radius: 28px; padding: clamp(40px, 7vw, 88px); text-align: center; background: radial-gradient(120% 140% at 10% 0%, color-mix(in srgb, var(--accent) 55%, var(--bg-2)), transparent 60%), radial-gradient(120% 140% at 100% 100%, color-mix(in srgb, var(--gold) 35%, var(--bg-2)), transparent 55%), var(--bg-2); border: 1px solid var(--rule); box-shadow: var(--shadow); }
        .cta h2 { font-size: clamp(32px, 5vw, 60px); max-width: 18ch; margin: 0 auto 16px; }
        .cta p { color: var(--text-soft); max-width: 56ch; margin: 0 auto 30px; font-size: 19px; }
        .btn { display: inline-flex; align-items: center; gap: 10px; font: inherit; font-weight: 700; font-size: 16px; padding: 15px 26px; border-radius: 999px; border: 0; background: var(--accent); color: var(--accent-ink); cursor: pointer; text-decoration: none; box-shadow: 0 12px 30px -10px var(--glow); transition: transform .25s var(--ease), box-shadow .25s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 18px 40px -12px var(--glow); }
        .btn.ghost { background: transparent; color: var(--text); border: 1px solid var(--rule); box-shadow: none; }

        .prose { max-width: 760px; color: var(--text-soft); font-size: 18px; }
        .prose h3 { color: var(--text); font-size: 24px; margin: 28px 0 10px; }
        .prose strong { color: var(--text); }
        .prose ul { padding-left: 22px; } .prose li { margin-bottom: 6px; }
        .prose > :last-child { margin-bottom: 0; }
        .backing { margin-top: 30px; display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); }
        .backing .quote { padding: 22px; box-shadow: none; }
        .backing .quote blockquote { font-size: 16px; }

        .docs { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); }
        .doc { display: flex; gap: 14px; align-items: center; padding: 18px 20px; border-radius: 14px; background: var(--surface); border: 1px solid var(--rule); text-decoration: none; transition: border-color .3s, transform .3s var(--ease); }
        a.doc:hover { border-color: var(--accent); transform: translateY(-2px); }

        /* Feedback, forms, flashes */
        .feedback { margin-top: 34px; max-width: 760px; }
        .feedback summary { cursor: pointer; font-weight: 700; font-size: 14px; color: var(--text-soft); list-style: none; display: inline-flex; gap: 8px; align-items: center; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--rule); }
        .feedback summary::-webkit-details-marker { display: none; }
        .note-box { margin-top: 12px; padding: 14px 16px; border-radius: 12px; background: var(--surface); border: 1px solid var(--rule); font-size: 15px; }
        label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        input[type=text], input[type=email], textarea { width: 100%; font: inherit; font-size: 16px; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--rule); background: var(--bg-2); color: var(--text); }
        input:focus, textarea:focus, button:focus-visible, a:focus-visible, summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .stack { display: grid; gap: 14px; }
        .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 14px; }
        .flash { position: fixed; z-index: 70; left: 50%; bottom: 24px; transform: translateX(-50%); max-width: calc(100% - 32px); padding: 14px 20px; border-radius: 14px; font-weight: 600; box-shadow: var(--shadow); animation: toast 5s var(--ease) forwards; }
        .flash.ok { background: #e3f2ea; color: #1f5f40; } .flash.err { background: #fbe5e3; color: #8c2219; }
        @keyframes toast { 0% { opacity: 0; transform: translate(-50%, 20px); } 8%, 85% { opacity: 1; transform: translate(-50%, 0); } 100% { opacity: 0; transform: translate(-50%, 20px); pointer-events: none; } }
        footer { padding-block: 56px 70px; font-size: 13px; color: var(--text-dim); border-top: 1px solid var(--rule); }

        /* The embedded proposal */
        .embedded .sheet { background: var(--surface); border: 1px solid var(--rule); border-radius: var(--radius); padding: clamp(22px, 4vw, 40px); }
        .embedded section { padding-block: 14px; }
        .embedded h2 { font-size: 28px; margin-bottom: 14px; }
        .embedded .md > :last-child { margin-bottom: 0; } .embedded .md ul { padding-left: 20px; }
        .embedded table { width: 100%; border-collapse: collapse; font-size: 15px; }
        .embedded th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-dim); padding: 8px 10px; border-bottom: 1px solid var(--rule); }
        .embedded td { padding: 12px 10px; border-bottom: 1px solid var(--rule); vertical-align: top; }
        .embedded .table-scroll { overflow-x: auto; }
        .embedded .claims { display: grid; gap: 14px; } .embedded .claim { border-left: 3px solid var(--accent); padding: 4px 0 4px 18px; }
        .embedded .claim blockquote { margin: 0; font-size: 18px; } .embedded .claim .fn { font-size: 13px; color: var(--text-dim); margin-top: 6px; }
        .embedded .benefits { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 14px; }
        .embedded .benefit { background: var(--surface-2); border-radius: 12px; padding: 16px 18px; }
        .embedded .note { background: var(--warn-tint); border-radius: 10px; padding: 12px 16px; font-size: 14px; }
        .embedded .lede { font-size: 19px; color: var(--text-soft); }
        .embedded .eyebrow { color: var(--text-soft); }

        /* Reveal on scroll */
        .rv { opacity: 0; transform: translateY(28px); transition: opacity .9s var(--ease), transform .9s var(--ease); transition-delay: calc(var(--i, 0) * 80ms); }
        .rv.in { opacity: 1; transform: none; }
        .no-js .rv { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .rv { opacity: 1; transform: none; transition: none; }
            .hero .orb, .cue::after { animation: none; }
        }

        /* The builder's preview */
        [data-edit] { cursor: pointer; }
        .editing [data-edit] { transition: box-shadow .2s; }
        .editing [data-edit]:hover { box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--accent) 60%, transparent); }
        .editing [data-edit].picked { box-shadow: inset 0 0 0 3px var(--accent); }
    </style>
    @stack('head')
</head>
<body class="no-js @yield('body-class')">
<script>document.body.classList.remove('no-js');</script>
@yield('body')
@stack('scripts')
</body>
</html>
