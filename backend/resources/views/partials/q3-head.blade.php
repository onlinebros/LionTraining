{{--
    Q3 premium theme — shared <head> block.

    Include this LAST in a layout's <head>, after every vendor and template
    stylesheet, so the theme layer wins the cascade:

        style.css → color-N.css → responsive.css → custom.css → (this partial)

    Carries the brand icons, the PWA manifest, the UI typeface and the two
    theme stylesheets. Design tokens live in assets/css/q3-theme.css.
--}}

{{-- Brand icons — the Q3 mark, not the template favicon. The browser icons are
     transparent; the apple-touch and manifest icons are backed with black,
     because phones fill a transparent home-screen icon with white. --}}
<link rel="icon" type="image/png" sizes="192x192" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}">
<link rel="icon" type="image/png" sizes="512x512" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-512.png') }}">
<link rel="shortcut icon" type="image/png" href="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\Asset::v('assets/images/logo/q3-apple-touch-icon-180.png') }}">

{{-- Saved to a phone home screen, the app opens standalone on the Q3 black. --}}
<link rel="manifest" href="{{ \App\Support\Asset::v('assets/q3.webmanifest') }}">
<meta name="theme-color" content="#050505">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
{{-- The label under the installed app's icon. iOS reads this; Android reads
     short_name in q3.webmanifest — keep the two identical. Fixed rather than
     the admin site_name so the platforms can't drift apart. --}}
<meta name="apple-mobile-web-app-title" content="Quantum Solution">
<meta name="color-scheme" content="dark">

{{-- Inter for the interface; Cormorant Garamond only for occasional display
     headings (see .q3-display) — never for menus or dashboard content. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cormorant+Garamond:wght@500;600&display=swap">

{{-- Generated palette remap (see scripts/build-q3-palette-map.py), then the
     hand-written theme. Order matters: the theme must load last.

     Versioned by file mtime: these are edited in place at a stable path, so
     without it a browser that has loaded the theme once keeps serving its own
     copy back and a deployed change looks like it never shipped. --}}
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-palette-map.css') }}">
<link rel="stylesheet" type="text/css" href="{{ \App\Support\Asset::v('assets/css/q3-theme.css') }}">
