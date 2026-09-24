/* ==========================================================================
   Q3 storefront — ion field
   --------------------------------------------------------------------------
   The moving background on a vendor product page whose config sets
   `ambient => 'ion-field'`. The product photo in the hero is the source:
   every time one of its gold rings pulses out, a wave of ions leaves the
   unit, spreads across the page, finds the faint patches of contamination
   scattered over it, and clears them with a small gold flash. Ions live a
   while and return to the unit; new patches settle elsewhere, so the
   cleaning never stops.

   Scroll the unit out of view and the ions keep coming in from the edge
   nearest it — the device is still working, just off screen.

   The unit is also plumbed in. Faint gold ductwork runs from it down both
   margins of the page, with vents along the way. Each wave sends a pulse
   through the ducts, and when it reaches a vent the vent lights and puffs
   out ions of its own — the building's HVAC carrying them room to room.
   Ducts and vents are laid out in page coordinates, so they scroll with the
   content like fixtures in a wall.

   The visitor can try it: over empty background (never text, a card, a
   control, the order form) the cursor becomes a hand, and a click plants a
   patch there. Every ion within reach turns and rushes it.

   Illustration, not data. It sits behind the content on a fixed canvas, takes
   no pointer events, and is sized to stay out of the way:

     - counts scale with the viewport area, so a phone gets a sparse field;
     - device pixel ratio is capped, so a retina laptop is not painting 4x;
     - the loop stops when the tab is hidden;
     - prefers-reduced-motion gets no canvas at all.

   Colours come from CSS custom properties (--q3-gold-rgb, --q3-sf-germ-rgb),
   so a palette change in q3-theme.css / q3-storefront.css reaches this too.
   ========================================================================== */
(function () {
    'use strict';

    var canvas = document.getElementById('q3-sf-ionfield');
    if (!canvas || !canvas.getContext) { return; }

    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    if (motion && motion.matches) { canvas.remove(); return; }

    var ctx = canvas.getContext('2d');
    var css = getComputedStyle(document.body);

    function rgb(name, fallback) {
        var v = (css.getPropertyValue(name) || '').trim();
        return v || fallback;
    }

    var GOLD = rgb('--q3-gold-rgb', '212, 175, 55');
    var GERM = rgb('--q3-sf-germ-rgb', '180, 72, 63');

    // Vent faces take the card colour, so a grille reads as a fixture set
    // into the page rather than a hole in it.
    var PLATE = (function (hex) {
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
        return m ? parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) : '20, 20, 22';
    })(rgb('--q3-surface', '#141416'));

    var W = 0, H = 0, dpr = 1;
    var ions = [], germs = [], bursts = [];
    var running = false, last = 0, t = 0;

    // The emitter: the hero product plate, and the first of its CSS rings,
    // whose animation clock we follow so each wave leaves with a ring.
    var source = document.querySelector('.q3-sf-hero-media');
    var ring = source ? source.querySelector('.q3-sf-ring') : null;
    var RING_PERIOD = 1400;           // ms between rings: 4.2s ÷ 3 rings
    var RING_TO_EDGE = 2200;          // ms for a ring to widen to the plate's edge
    var lastWave = -1, waveClock = 0;

    // Ductwork, in page (document) coordinates. Rebuilt by plan().
    var ducts = [], vents = [], pulses = [];
    var PULSE_SPEED = 560;            // px per second along the duct

    function rand(a, b) { return a + Math.random() * (b - a); }

    /* ---- Contamination ----------------------------------------------------
       A patch is a loose cluster of soft dots. It fades in, sits there
       wobbling until enough ions have passed through it, then shrinks away
       into a burst. Health, not a timer, decides when — so the clearing is
       visibly caused by the ions reaching it.                                */
    function makeGerm(delay, at) {
        var dots = [], n = Math.floor(rand(5, 10)), r = at ? rand(26, 36) : rand(16, 34);
        for (var i = 0; i < n; i++) {
            var a = rand(0, Math.PI * 2), d = rand(0, r);
            dots.push({ x: Math.cos(a) * d, y: Math.sin(a) * d, r: rand(3, 9), p: rand(0, 6.28) });
        }
        return {
            x: at ? at[0] : rand(W * 0.04, W * 0.96),
            y: at ? at[1] : rand(H * 0.06, H * 0.94),
            r: r + 10,
            dots: dots,
            health: 1,
            seeded: !!at,         // planted by a click: called in hard, not replaced
            appear: at ? 0.5 : 0, // 0 → 1 fade-in
            wait: delay || 0,     // seconds before it shows up
            dying: 0              // 0 = alive, then 0 → 1 as it is cleared
        };
    }

    /* ---- Ions -------------------------------------------------------------
       Carried by a slow, smoothly varying flow — the building's airflow — and
       steered gently toward the nearest patch within reach.                  */
    function makeIon() {
        return {
            x: 0, y: 0, vx: 0, vy: 0,
            s: rand(0.7, 1.8),
            tw: rand(0, 6.28),
            age: 0, life: 0,
            live: false           // false = back at the unit, waiting for a wave
        };
    }

    // Where the unit is right now, in canvas (viewport) coordinates, pinned
    // just outside the edge when it has been scrolled away. hw/hh are the
    // plate's half-size: the plate is opaque and above the canvas, so ions
    // are released at its edge, where they can be seen.
    function origin() {
        if (!source) { return { x: W / 2, y: -20, hw: 0, hh: 0 }; }
        var b = source.getBoundingClientRect();
        var cx = b.left + b.width / 2, cy = b.top + b.height * 0.52;
        var onScreen = b.bottom > 0 && b.top < H;
        return {
            x: Math.min(W + 20, Math.max(-20, cx)),
            y: Math.min(H + 20, Math.max(-20, cy)),
            hw: onScreen ? b.width / 2 : 0,
            hh: onScreen ? b.height / 2 : 0
        };
    }

    function launch(p, o) {
        var a = rand(0, Math.PI * 2), v = rand(2.2, 3.6);
        var c = Math.abs(Math.cos(a)) || 1e-6, sn = Math.abs(Math.sin(a)) || 1e-6;
        // Distance from the centre to the plate's edge along this heading.
        var r = Math.min(o.hw / c, o.hh / sn) + 2;
        p.x = o.x + Math.cos(a) * r;
        p.y = o.y + Math.sin(a) * r;
        p.vx = Math.cos(a) * v;
        p.vy = Math.sin(a) * v;
        p.age = 0;
        p.life = rand(16, 26);
        p.live = true;
    }

    // One wave: a share of the waiting ions, sized so the pool turns over
    // across an ion's lifetime rather than emptying on the first ring — and
    // a pulse down the ducts toward every vent.
    function wave() {
        var o = origin(), n = Math.max(6, Math.round(ions.length / 8)), sent = 0;
        for (var i = 0; i < ions.length && sent < n; i++) {
            if (!ions[i].live) { launch(ions[i], o); sent++; }
        }
        for (var v = 0; v < vents.length; v++) { pulses.push({ vent: vents[v], s: 0, wave: lastWave }); }
    }

    /* ---- Ductwork ---------------------------------------------------------
       From the right side of the plate across to the right margin, down to
       the foot of the hero, along the hero's bottom edge to the left margin,
       then down both margins. Routed around the copy, never through it: the
       hero's stats row and every section's text sit between the trunks.

       Vents alternate sides down the page and stop above the order form —
       that section is solid, and nothing should move behind someone typing.  */
    function plan() {
        ducts = []; vents = []; pulses = [];
        if (!source) { return; }

        var sy = window.scrollY || 0;
        var plate = source.getBoundingClientRect();
        var hero = source.closest('.q3-sf-hero');
        var wrap = document.querySelector('.q3-sf-hero .q3-sf-wrap');
        var form = document.getElementById('enquire');
        if (!hero || !wrap) { return; }

        // Trunks sit in the middle of the empty margin when there is one,
        // and hug the screen edge on a phone.
        var gutter = wrap.getBoundingClientRect().left;
        var xL = gutter >= 70 ? Math.round(gutter / 2) : 10;
        var xR = W - xL;

        var py = plate.top + plate.height * 0.52 + sy;
        var hy = hero.getBoundingClientRect().bottom + sy - 1;
        var end = (form ? form.getBoundingClientRect().top + sy : document.documentElement.scrollHeight) - 90;

        var head = [[plate.right, py], [xR, py], [xR, hy]];
        var spacing = W < 768 ? 380 : 460;
        var lastL = hy, lastR = hy, side = -1;

        for (var y = hy + 170; y < end; y += spacing / 2) {
            var path = side < 0
                ? head.concat([[xL, hy], [xL, y]])
                : head.concat([[xR, y]]);
            vents.push({ x: side < 0 ? xL : xR, y: y, side: side, path: path, len: pathLength(path), glow: 0 });
            if (side < 0) { lastL = y; } else { lastR = y; }
            side = -side;
        }

        ducts.push(head);
        ducts.push([[xR, hy], [xL, hy], [xL, lastL]]);
        if (lastR > hy) { ducts.push([[xR, hy], [xR, lastR]]); }
    }

    function pathLength(path) {
        var len = 0;
        for (var i = 1; i < path.length; i++) {
            len += Math.abs(path[i][0] - path[i - 1][0]) + Math.abs(path[i][1] - path[i - 1][1]);
        }
        return len;
    }

    // The point `s` px along an axis-aligned path.
    function along(path, s) {
        for (var i = 1; i < path.length; i++) {
            var a = path[i - 1], b = path[i];
            var seg = Math.abs(b[0] - a[0]) + Math.abs(b[1] - a[1]);
            if (s <= seg) {
                var f = seg ? s / seg : 0;
                return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];
            }
            s -= seg;
        }
        return path[path.length - 1];
    }

    // A vent reached by a pulse: it lights, and if it is on screen it
    // breathes a handful of ions out across the page.
    function puff(v) {
        v.glow = 1;
        var vy = v.y - (window.scrollY || 0);
        if (vy < -60 || vy > H + 60) { return; }

        var dir = v.side < 0 ? 0 : Math.PI, sent = 0;
        for (var i = 0; i < ions.length && sent < 5; i++) {
            var p = ions[i];
            if (p.live) { continue; }
            var a = dir + rand(-0.8, 0.8), sp = rand(1.8, 3);
            p.x = v.x + Math.cos(a) * 10;
            p.y = vy + rand(-14, 14);
            p.vx = Math.cos(a) * sp;
            p.vy = Math.sin(a) * sp;
            p.age = 0;
            p.life = rand(14, 22);
            p.live = true;
            sent++;
        }
    }

    // Follow the ring's own animation clock where the browser exposes it, so
    // the wave and the ring leave together; otherwise keep our own time.
    function tickWaves(dtMs) {
        var now;
        var anims = ring && ring.getAnimations ? ring.getAnimations() : [];
        if (anims.length && anims[0].currentTime != null) {
            now = anims[0].currentTime;
        } else {
            waveClock += dtMs;
            now = waveClock;
        }
        // Offset so a wave leaves as its ring reaches the plate's edge.
        var n = Math.floor((now - RING_TO_EDGE) / RING_PERIOD);
        if (n !== lastWave) { lastWave = n; wave(); }
    }

    function burst(x, y, tint) {
        var sparks = [];
        for (var i = 0; i < 9; i++) {
            var a = (i / 9) * Math.PI * 2 + rand(-0.2, 0.2), v = rand(0.6, 1.6);
            sparks.push({ x: x, y: y, vx: Math.cos(a) * v, vy: Math.sin(a) * v });
        }
        bursts.push({ x: x, y: y, life: 0, sparks: sparks, rgb: tint || GOLD });
    }

    function resize() {
        // A phone fires resize whenever its URL bar slides away on scroll.
        // Height-only changes resize the canvas but keep the field as it is,
        // or every scroll would visibly reset the patches.
        var widthChanged = window.innerWidth !== W;

        dpr = Math.min(window.devicePixelRatio || 1, 1.5);
        W = window.innerWidth;
        H = window.innerHeight;
        canvas.width = Math.round(W * dpr);
        canvas.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        var area = W * H;
        var ionCount = Math.max(70, Math.min(300, Math.round(area / 4800)));
        var germCount = Math.max(4, Math.min(12, Math.round(area / 110000)));

        var fresh = ions.length === 0;
        while (ions.length < ionCount) { ions.push(makeIon()); }
        ions.length = ionCount;

        // On first paint, a third of the ions are already out and part-way
        // through their lives, so a visitor landing mid-page is not looking at
        // an empty field while the first waves travel.
        if (fresh) {
            for (var q = 0; q < ionCount / 3; q++) {
                var p = ions[q];
                p.live = true;
                p.x = rand(0, W); p.y = rand(0, H);
                p.vx = rand(-0.3, 0.3); p.vy = rand(-0.3, 0.3);
                p.life = rand(16, 26); p.age = rand(1.5, p.life * 0.7);
            }
        }

        plan();

        if (!widthChanged) { return; }
        germs = [];
        for (var i = 0; i < germCount; i++) {
            germs.push(makeGerm(rand(0, 4)));
        }
    }

    function flow(x, y) {
        // Two slow sine fields layered — a gentle, curling drift with no
        // visible grid or repeat. Cheap enough for a phone.
        var a = Math.sin(x * 0.0023 + t * 0.21) + Math.cos(y * 0.0031 - t * 0.17);
        var b = Math.cos(x * 0.0019 - t * 0.13) - Math.sin(y * 0.0027 + t * 0.19);
        return [a * 0.22 + 0.06, b * 0.18 - 0.05];
    }

    function step(dt) {
        t += dt;
        var i, j, g, p, k = dt * 60;

        for (i = germs.length - 1; i >= 0; i--) {
            g = germs[i];
            if (g.wait > 0) { g.wait -= dt; continue; }
            if (g.dying > 0) {
                g.dying += dt * 1.4;
                if (g.dying >= 1) {
                    if (g.seeded) { germs.splice(i, 1); } else { germs[i] = makeGerm(rand(1.5, 5)); }
                }
                continue;
            }
            g.appear = Math.min(1, g.appear + dt * 0.35);
            if (g.health <= 0) { g.dying = 0.001; burst(g.x, g.y); }
        }

        tickWaves(dt * 1000);

        for (i = pulses.length - 1; i >= 0; i--) {
            var pu = pulses[i];
            pu.s += PULSE_SPEED * dt;
            if (pu.s >= pu.vent.len) { puff(pu.vent); pulses.splice(i, 1); }
        }
        for (i = 0; i < vents.length; i++) {
            vents[i].glow = Math.max(0, vents[i].glow - dt * 1.1);
        }

        for (i = 0; i < ions.length; i++) {
            p = ions[i];
            if (!p.live) { continue; }
            p.age += dt;
            if (p.age >= p.life) { p.live = false; continue; }

            // Leaving the unit the ion coasts outward on its launch speed,
            // easing into the airflow; the ring shape holds for a moment
            // before the field takes over.
            var launching = p.age < 1.2;
            var f = flow(p.x, p.y);
            var ease = launching ? 0.012 : 0.02;
            p.vx += (f[0] - p.vx) * ease * k;
            p.vy += (f[1] - p.vy) * ease * k;

            // Seek: the nearest live patch within 340px pulls. A planted one
            // calls from 800px and counts as twice as close, so a click
            // visibly turns the field toward it.
            var best = null, bd = Infinity, bestD2 = 0;
            for (j = 0; j < germs.length; j++) {
                g = germs[j];
                if (g.wait > 0 || g.dying > 0 || g.appear < 0.3) { continue; }
                var dx = g.x - p.x, dy = g.y - p.y, d2 = dx * dx + dy * dy;
                if (d2 > (g.seeded ? 640000 : 115600)) { continue; }
                var score = g.seeded ? d2 * 0.25 : d2;
                if (score < bd) { bd = score; best = g; bestD2 = d2; }
            }
            if (best && !launching) {
                var d = Math.sqrt(bestD2) || 1, pull = best.seeded ? 0.08 : 0.03;
                p.vx += ((best.x - p.x) / d) * pull * k;
                p.vy += ((best.y - p.y) / d) * pull * k;
                if (d < best.r) { best.health -= (best.seeded ? 0.006 : 0.008) * k; }
            }

            // Cap speed so the seek never turns into a swarm — except toward a
            // planted patch, where the rush is the point.
            var cap = launching ? 4 : (best && best.seeded ? 3.2 : 1.6);
            var sp = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
            if (sp > cap) { p.vx *= cap / sp; p.vy *= cap / sp; }

            p.x += p.vx * k;
            p.y += p.vy * k;

            // Off the page is done: it goes back to the unit for a later wave.
            // Not while launching — with the unit scrolled away, a wave starts
            // outside the viewport and has to be allowed to travel in.
            if (!launching && (p.x < -40 || p.x > W + 40 || p.y < -40 || p.y > H + 40)) { p.live = false; }
        }

        for (i = bursts.length - 1; i >= 0; i--) {
            var b = bursts[i];
            b.life += dt * 0.9;
            for (j = 0; j < b.sparks.length; j++) {
                b.sparks[j].x += b.sparks[j].vx * k;
                b.sparks[j].y += b.sparks[j].vy * k;
                b.sparks[j].vx *= 0.96; b.sparks[j].vy *= 0.96;
            }
            if (b.life >= 1) { bursts.splice(i, 1); }
        }
    }

    function draw() {
        ctx.clearRect(0, 0, W, H);
        var i, j, g, p;
        var sy = window.scrollY || 0;

        drawDucts(sy);

        // Contamination — muted and soft-edged; it should read as a stain in
        // the air, not an alarm.
        for (i = 0; i < germs.length; i++) {
            g = germs[i];
            if (g.wait > 0) { continue; }
            var life = g.dying > 0 ? 1 - g.dying : g.appear;
            var scale = g.dying > 0 ? 1 - g.dying * 0.7 : 0.6 + g.appear * 0.4;
            var alpha = life * (0.16 + 0.22 * Math.max(0, g.health));
            for (j = 0; j < g.dots.length; j++) {
                var dot = g.dots[j];
                var wob = Math.sin(t * 1.3 + dot.p) * 2;
                var x = g.x + (dot.x + wob) * scale, y = g.y + (dot.y - wob) * scale;
                var r = dot.r * scale * (0.85 + 0.15 * Math.sin(t * 2 + dot.p));
                var grad = ctx.createRadialGradient(x, y, 0, x, y, r * 1.8);
                grad.addColorStop(0, 'rgba(' + GERM + ',' + alpha + ')');
                grad.addColorStop(1, 'rgba(' + GERM + ',0)');
                ctx.fillStyle = grad;
                ctx.beginPath();
                ctx.arc(x, y, r * 1.8, 0, 6.2832);
                ctx.fill();
            }
        }

        // Ions — a short tail along the direction of travel and a twinkling
        // head. Lighter composite so crossings glow rather than muddy.
        ctx.globalCompositeOperation = 'lighter';
        ctx.lineCap = 'round';
        for (i = 0; i < ions.length; i++) {
            p = ions[i];
            if (!p.live) { continue; }
            // Bright as it leaves the unit, fading out over its last 2s.
            var fade = Math.min(1, (p.life - p.age) / 2) * (p.age < 1.2 ? 1.35 - p.age * 0.3 : 1);
            var tw = (0.55 + 0.45 * Math.sin(t * 2.4 + p.tw)) * fade;
            ctx.strokeStyle = 'rgba(' + GOLD + ',' + (0.24 * tw) + ')';
            ctx.lineWidth = p.s;
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(p.x - p.vx * 7, p.y - p.vy * 7);
            ctx.stroke();

            ctx.fillStyle = 'rgba(' + GOLD + ',' + Math.min(1, (0.35 + 0.4 * tw) * fade) + ')';
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.s * 1.1, 0, 6.2832);
            ctx.fill();
        }

        // Clearing bursts — an expanding gold ring and a scatter of sparks.
        for (i = 0; i < bursts.length; i++) {
            var b = bursts[i], fade = 1 - b.life;
            ctx.strokeStyle = 'rgba(' + b.rgb + ',' + (0.45 * fade) + ')';
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.arc(b.x, b.y, 8 + b.life * 46, 0, 6.2832);
            ctx.stroke();
            ctx.fillStyle = 'rgba(' + b.rgb + ',' + (0.8 * fade) + ')';
            for (j = 0; j < b.sparks.length; j++) {
                ctx.beginPath();
                ctx.arc(b.sparks[j].x, b.sparks[j].y, 1.3, 0, 6.2832);
                ctx.fill();
            }
        }
        ctx.globalCompositeOperation = 'source-over';
    }

    function drawDucts(sy) {
        if (!ducts.length) { return; }
        var i, j, d;

        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        // The duct: a soft wide band, with a fine dashed centre line flowing
        // away from the unit.
        for (i = 0; i < ducts.length; i++) {
            d = ducts[i];
            ctx.beginPath();
            ctx.moveTo(d[0][0], d[0][1] - sy);
            for (j = 1; j < d.length; j++) { ctx.lineTo(d[j][0], d[j][1] - sy); }
            ctx.setLineDash([]);
            ctx.strokeStyle = 'rgba(' + GOLD + ',0.05)';
            ctx.lineWidth = 8;
            ctx.stroke();
            ctx.setLineDash([2, 12]);
            ctx.lineDashOffset = -t * 42;
            ctx.strokeStyle = 'rgba(' + GOLD + ',0.22)';
            ctx.lineWidth = 1;
            ctx.stroke();
        }
        ctx.setLineDash([]);

        // Vents: a grille on the trunk, glowing as a pulse arrives.
        var sc = W < 768 ? 0.6 : 1, vw = 30 * sc, vh = 44 * sc;
        for (i = 0; i < vents.length; i++) {
            var v = vents[i], vy = v.y - sy;
            if (vy < -60 || vy > H + 60) { continue; }

            if (v.glow > 0) {
                var halo = ctx.createRadialGradient(v.x, vy, 0, v.x, vy, 70 * sc);
                halo.addColorStop(0, 'rgba(' + GOLD + ',' + (0.32 * v.glow) + ')');
                halo.addColorStop(1, 'rgba(' + GOLD + ',0)');
                ctx.fillStyle = halo;
                ctx.beginPath();
                ctx.arc(v.x, vy, 70 * sc, 0, 6.2832);
                ctx.fill();
            }

            var x0 = v.x - vw / 2, y0 = vy - vh / 2;
            ctx.fillStyle = 'rgba(' + PLATE + ',0.96)';
            ctx.strokeStyle = 'rgba(' + GOLD + ',' + (0.28 + 0.55 * v.glow) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath();
            if (ctx.roundRect) { ctx.roundRect(x0, y0, vw, vh, 4 * sc); } else { ctx.rect(x0, y0, vw, vh); }
            ctx.fill();
            ctx.stroke();

            ctx.strokeStyle = 'rgba(' + GOLD + ',' + (0.22 + 0.6 * v.glow) + ')';
            ctx.beginPath();
            for (j = 1; j <= 5; j++) {
                var ly = y0 + (vh / 6) * j;
                ctx.moveTo(x0 + 5 * sc, ly);
                ctx.lineTo(x0 + vw - 5 * sc, ly);
            }
            ctx.stroke();
        }

        // Pulses travelling the ducts toward their vents.
        // Every pulse in a wave shares the trunk until its branch; drawn once
        // per spot, or the shared run would stack into a blaze.
        ctx.globalCompositeOperation = 'lighter';
        var seen = {};
        for (i = 0; i < pulses.length; i++) {
            var pt = along(pulses[i].vent.path, pulses[i].s), py = pt[1] - sy;
            if (py < -20 || py > H + 20) { continue; }
            var key = pulses[i].wave + ':' + Math.round(pt[0] / 4) + ':' + Math.round(pt[1] / 4);
            if (seen[key]) { continue; }
            seen[key] = true;
            var gl = ctx.createRadialGradient(pt[0], py, 0, pt[0], py, 12);
            gl.addColorStop(0, 'rgba(' + GOLD + ',0.55)');
            gl.addColorStop(1, 'rgba(' + GOLD + ',0)');
            ctx.fillStyle = gl;
            ctx.beginPath();
            ctx.arc(pt[0], py, 12, 0, 6.2832);
            ctx.fill();
        }
        ctx.globalCompositeOperation = 'source-over';
    }

    function frame(now) {
        if (!running) { return; }
        var dt = Math.min(0.05, (now - last) / 1000 || 0.016);
        last = now;
        step(dt);
        draw();
        requestAnimationFrame(frame);
    }

    function start() {
        if (running) { return; }
        running = true;
        last = performance.now();
        requestAnimationFrame(frame);
    }
    function stop() { running = false; }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(resize, 150);
    });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stop(); } else { start(); }
    });
    if (motion && motion.addEventListener) {
        motion.addEventListener('change', function (e) {
            if (e.matches) { stop(); canvas.remove(); }
        });
    }

    /* ---- Plant a germ -----------------------------------------------------
       Only on empty background: the click has to land on a layout container
       itself, not on anything inside it, and never in the order form, the
       brand bar or the footer. Text stays selectable, links and controls
       behave as they always did.                                             */
    var OPEN_GROUND = 'body, .q3-sf-hero, .q3-sf-section, .q3-sf-wrap, .q3-sf-hero-grid, '
        + '.q3-sf-hero-copy, .q3-sf-stats, .q3-sf-grid, .q3-sf-versus, .q3-sf-gallery, '
        + '.q3-sf-specs, .q3-sf-chips';
    var MAX_PLANTED = 6;
    var root = document.documentElement;

    function isOpenGround(el) {
        return !!el && el.matches && el.matches(OPEN_GROUND)
            && !el.closest('#enquire, .q3-sf-brandbar, .q3-sf-footer');
    }

    document.addEventListener('pointermove', function (e) {
        root.classList.toggle('q3-sf-seeding', isOpenGround(e.target));
    }, { passive: true });

    document.addEventListener('click', function (e) {
        if (!running || !isOpenGround(e.target)) { return; }
        var sel = window.getSelection && window.getSelection();
        if (sel && String(sel).length) { return; }   // finishing a text selection

        var planted = germs.filter(function (g) { return g.seeded && !g.dying; });
        if (planted.length >= MAX_PLANTED) { planted[0].health = 0; }

        germs.push(makeGerm(0, [e.clientX, e.clientY]));
        burst(e.clientX, e.clientY, GERM);
    });

    // Images and fonts settle after first paint and move the sections; the
    // ducts are page fixtures, so re-plan whenever the page changes height.
    if (window.ResizeObserver) {
        var planTimer;
        new ResizeObserver(function () {
            clearTimeout(planTimer);
            planTimer = setTimeout(plan, 200);
        }).observe(document.body);
    }

    resize();
    canvas.classList.add('is-live');
    start();
})();
