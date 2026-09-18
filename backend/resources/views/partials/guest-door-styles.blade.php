{{--
    The look of a guest front door.

    Shared by the single-showing registration page and the funnel entry page so
    the two are the same page as far as a prospect is concerned — which they
    are: both are the first thing somebody ever sees of the company, and both
    lead into the same dark room.

    Usage:
        @include('partials.guest-door-styles')
--}}
<style>
    /*
     * Genuinely the first thing a prospect ever sees of the company, so it is
     * treated as a front door rather than a form. Shares the room's dark,
     * cinematic palette so the door and the room read as one experience.
     */
    :root {
        --room-bg:      #090C13;
        --room-panel:   #121724;
        --room-raised:  #182031;
        --room-line:    #222C40;
        --room-text:    #E9EDF6;
        --room-muted:   #8894AC;
        --room-accent:  var(--theme-default, #f27209);
    }

    body { background: var(--room-bg); color: var(--room-text); }

    .reg {
        min-height:100vh; display:flex; align-items:center; justify-content:center;
        padding:40px 18px 64px; position:relative; overflow:hidden;
    }
    /* A single soft bloom behind the card. One flourish, not five. */
    .reg::before {
        content:""; position:absolute; top:-20%; left:50%; transform:translateX(-50%);
        width:min(900px,120vw); height:520px; pointer-events:none;
        background:radial-gradient(50% 50% at 50% 50%, rgba(var(--rgb-primary,242,114,9),.26), transparent 70%);
        filter:blur(60px);
    }

    .reg__inner { position:relative; z-index:1; width:100%; max-width:520px; }
    .reg__logo { display:block; text-align:center; margin-bottom:26px; }

    .reg__card {
        background:var(--room-panel); border:1px solid var(--room-line);
        border-radius:18px; padding:30px; box-shadow:0 30px 70px rgba(0,0,0,.55);
    }

    .when {
        display:inline-flex; align-items:center; gap:8px; margin-bottom:16px;
        background:rgba(var(--rgb-primary,242,114,9),.14);
        border:1px solid rgba(var(--rgb-primary,242,114,9),.3);
        color:var(--room-accent); border-radius:999px; padding:6px 15px;
        font-size:12.5px; font-weight:600;
    }
    .when--live { background:rgba(255,68,56,.12); border-color:rgba(255,68,56,.35); color:#FF4438; }
    .when--done { background:var(--room-raised); border-color:var(--room-line); color:var(--room-muted); }
    .when .dot { width:7px; height:7px; border-radius:50%; background:currentColor; }

    /* Colour stated outright: the theme's own h1 rule wins over anything
       inherited from body, and dark-on-dark is invisible. */
    .reg__title {
        font-size:clamp(21px,3.4vw,28px); font-weight:700; letter-spacing:-.02em;
        margin:0 0 10px; color:var(--room-text);
    }
    .reg__desc { color:#C3CCDD; line-height:1.65; white-space:pre-line; margin-bottom:20px; }

    .host {
        display:flex; align-items:center; gap:12px; margin-bottom:22px;
        background:var(--room-raised); border:1px solid var(--room-line);
        border-radius:12px; padding:13px 15px;
    }
    .host__avatar {
        width:38px; height:38px; border-radius:50%; flex:none;
        background:linear-gradient(135deg, var(--room-accent), #ffffff26);
        color:#fff; font-weight:700; font-size:15px;
        display:flex; align-items:center; justify-content:center;
    }
    .host__text { font-size:13.5px; color:var(--room-muted); line-height:1.5; }
    .host__text strong { color:var(--room-text); }

    .field { margin-bottom:15px; }
    .field label { display:block; font-size:13px; color:var(--room-muted); margin-bottom:6px; }
    .field input {
        width:100%; border-radius:11px; padding:12px 14px; font-size:15px;
        background:var(--room-raised); border:1px solid var(--room-line);
        color:var(--room-text); transition:border-color .12s;
    }
    .field input::placeholder { color:#5D6880; }
    .field input:focus { outline:none; border-color:var(--room-accent); }
    .field .err { color:#FF7A6E; font-size:12.5px; margin-top:5px; }

    .btn-go {
        width:100%; border:0; border-radius:999px; padding:14px 20px;
        background:var(--room-accent); color:#fff; font-weight:650; font-size:16px;
        cursor:pointer; transition:transform .12s ease, filter .12s ease;
    }
    .btn-go:hover { transform:translateY(-1px); filter:brightness(1.08); }
    .btn-go:active { transform:translateY(0); }

    .note { font-size:12px; color:var(--room-muted); line-height:1.6; margin:16px 0 0; }
    .closed { background:var(--room-raised); border:1px solid var(--room-line);
              border-radius:12px; padding:16px; color:var(--room-muted); font-size:14px; }

    /* What is inside the flow. Shown before anyone commits an email address,
       because "three short videos, you pick" is the reason to start. */
    .steps { list-style:none; margin:0 0 22px; padding:0; }
    .steps li {
        display:flex; gap:11px; align-items:flex-start; padding:9px 0;
        border-bottom:1px solid var(--room-line); font-size:13.5px; color:#C3CCDD;
    }
    .steps li:last-child { border-bottom:0; }
    .steps__n {
        flex:none; width:22px; height:22px; border-radius:50%; margin-top:1px;
        background:var(--room-raised); border:1px solid var(--room-line);
        color:var(--room-muted); font-size:11.5px; font-weight:700;
        display:flex; align-items:center; justify-content:center;
    }
    .steps__len { color:var(--room-muted); font-size:12.5px; }

    :focus-visible { outline:2px solid var(--room-accent); outline-offset:2px; }
    @media (prefers-reduced-motion: reduce) { .btn-go { transition:none; } }
</style>
