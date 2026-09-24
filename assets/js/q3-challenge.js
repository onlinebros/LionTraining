/* ==========================================================================
   Q3 storefront — "Can you keep up?" challenge
   --------------------------------------------------------------------------
   Two arenas, one germ schedule. Every germ appears in both at the same
   moment and the same relative spot; the product's ions clear one side, the
   visitor taps the other. Germs left alone split, and the spawn rate climbs
   through the round, so falling behind compounds — which is the point.

   Positions are kept in arena-relative units (0..1) so the two surfaces can
   be different pixel sizes (stacked on a phone) and still share a schedule.

   The page is decoration around a sales page, so: no storage, no network,
   pause when the tab is hidden, DPR capped.
   ========================================================================== */
(function () {
    'use strict';

    var board = document.getElementById('cg-board');
    if (!board) { return; }

    var css = getComputedStyle(document.body);
    function v(name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback; }
    var GOLD = v('--q3-gold-rgb', '212, 175, 55');
    var GERM = v('--q3-sf-germ-rgb', '180, 72, 63');
    var INK  = '245, 241, 232';   // --q3-text as a triplet, for the cloth

    var ROUND = parseInt(board.dataset.seconds || '30', 10);
    var SPLIT_AFTER = 3.5;        // seconds a germ is left before it splits
    var CAP = 60;                 // germs per arena; past this the surface is lost
    var POINTS = 10;

    var $ = function (id) { return document.getElementById(id); };
    var overlay = $('cg-overlay');
    var timeEl = $('cg-time');

    function rand(a, b) { return a + Math.random() * (b - a); }

    // Seeded, so both arenas draw the same schedule from one seed.
    function rng(seed) {
        return function () {
            seed |= 0; seed = seed + 0x6D2B79F5 | 0;
            var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
            t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
            return ((t ^ t >>> 14) >>> 0) / 4294967296;
        };
    }

    /* ---- Schedule ---------------------------------------------------------
       Gaps shrink from ~0.85s to ~0.15s across the round, and from a third of
       the way in some spawns come in pairs.                                   */
    function schedule(seed) {
        var r = rng(seed), list = [], t = 0.4;
        while (t < ROUND) {
            var p = t / ROUND;
            var n = p > 0.33 && r() < p * 0.6 ? 2 : 1;
            for (var i = 0; i < n; i++) {
                list.push({ t: t, x: 0.08 + r() * 0.84, y: 0.1 + r() * 0.78, s: 0.85 + r() * 0.3 });
            }
            t += 0.85 - 0.7 * Math.pow(p, 1.1);
        }
        return list;
    }

    /* ---- Arena ------------------------------------------------------------ */
    function Arena(canvas, auto) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.auto = auto;          // true = the PRO's ions do the work
        this.germs = [];
        this.fx = [];
        this.ions = [];
        this.score = 0;
        this.w = 0; this.h = 0;
        this.resize();
    }

    Arena.prototype.resize = function () {
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var r = this.canvas.getBoundingClientRect();
        this.w = r.width; this.h = r.height;
        this.canvas.width = Math.round(r.width * dpr);
        this.canvas.height = Math.round(r.height * dpr);
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        this.unit = Math.min(this.w, this.h);

        if (this.auto) {
            var n = Math.max(140, Math.min(320, Math.round(this.w * this.h / 800)));
            while (this.ions.length < n) { this.ions.push(this.spawnIon({})); }
            this.ions.length = n;
        }
    };

    // Where the PRO's ions come from: the unit at the foot, vents high on
    // each side. Arena-relative.
    Arena.prototype.emitters = [[0.5, 0.97], [0.03, 0.22], [0.97, 0.22]];

    Arena.prototype.spawnIon = function (p) {
        var e = this.emitters[Math.floor(Math.random() * this.emitters.length)];
        var a = rand(0, Math.PI * 2);
        p.x = e[0] * this.w + rand(-6, 6);
        p.y = e[1] * this.h + rand(-6, 6);
        p.vx = Math.cos(a) * 2; p.vy = Math.sin(a) * 2 - (e[1] > 0.5 ? 2 : 0);
        p.age = 0; p.life = rand(3, 6);
        p.s = rand(0.8, 1.8);
        return p;
    };

    Arena.prototype.reset = function () {
        this.germs = []; this.fx = []; this.score = 0;
        if (this.auto) {
            for (var i = 0; i < this.ions.length; i++) {
                this.spawnIon(this.ions[i]);
                this.ions[i].age = rand(0, this.ions[i].life);
            }
        }
    };

    Arena.prototype.add = function (x, y, s) {
        if (this.germs.length >= CAP) { return; }
        this.germs.push({
            x: x, y: y, s: s,
            age: 0, split: SPLIT_AFTER * rand(0.9, 1.1),
            hp: 1, spin: rand(0, 6.28), dying: 0
        });
    };

    Arena.prototype.radius = function (g) {
        var pop = Math.min(1, g.age / 0.25);
        return this.unit * 0.045 * g.s * (0.5 + 0.5 * pop);
    };

    Arena.prototype.kill = function (g, byHand) {
        g.dying = 0.001;
        this.score += POINTS;
        var px = g.x * this.w, py = g.y * this.h, sparks = [];
        for (var i = 0; i < 10; i++) {
            var a = (i / 10) * 6.283 + rand(-0.2, 0.2), sp = rand(0.8, 2.2);
            sparks.push([px, py, Math.cos(a) * sp, Math.sin(a) * sp]);
        }
        this.fx.push({ x: px, y: py, life: 0, sparks: sparks, rgb: byHand ? INK : GOLD });
        this.fx.push({ x: px, y: py - 6, life: 0, text: '+' + POINTS, rgb: byHand ? INK : GOLD });
    };

    Arena.prototype.alive = function () {
        var n = 0;
        for (var i = 0; i < this.germs.length; i++) { if (!this.germs[i].dying) { n++; } }
        return n;
    };

    Arena.prototype.step = function (dt, playing) {
        var k = dt * 60, i, j, g;

        // Germs: pop in, wobble, split if left alone, fade when cleared.
        for (i = this.germs.length - 1; i >= 0; i--) {
            g = this.germs[i];
            g.age += dt;
            if (g.dying) {
                g.dying += dt * 3.5;
                if (g.dying >= 1) { this.germs.splice(i, 1); }
                continue;
            }
            if (playing && g.age >= g.split) {
                g.split += SPLIT_AFTER * rand(0.9, 1.1);
                var a = rand(0, 6.283), d = 0.07;
                this.add(Math.min(0.95, Math.max(0.05, g.x + Math.cos(a) * d)),
                         Math.min(0.92, Math.max(0.08, g.y + Math.sin(a) * d)), g.s * 0.9);
            }
        }

        // The PRO's ions: wander when there is nothing to do, and converge on
        // the nearest germ the moment there is.
        if (this.auto) {
            var sc = this.unit / 400;
            for (i = 0; i < this.ions.length; i++) {
                var p = this.ions[i];
                p.age += dt;
                if (p.age >= p.life) { this.spawnIon(p); continue; }

                var best = null, bd = Infinity;
                for (j = 0; j < this.germs.length; j++) {
                    g = this.germs[j];
                    if (g.dying) { continue; }
                    var dx = g.x * this.w - p.x, dy = g.y * this.h - p.y, d2 = dx * dx + dy * dy;
                    if (d2 < bd) { bd = d2; best = g; }
                }
                if (best) {
                    var d = Math.sqrt(bd) || 1;
                    p.vx += ((best.x * this.w - p.x) / d) * 0.55 * sc * k;
                    p.vy += ((best.y * this.h - p.y) / d) * 0.55 * sc * k;
                    if (d < this.radius(best) + 3) {
                        best.hp -= 0.07 * k;
                        if (best.hp <= 0 && playing) { this.kill(best, false); }
                    }
                } else {
                    p.vx += Math.sin(p.y * 0.02 + p.age) * 0.05 * k;
                    p.vy += Math.cos(p.x * 0.02 + p.age) * 0.05 * k;
                }
                p.vx *= Math.pow(0.95, k); p.vy *= Math.pow(0.95, k);
                var cap = 7.5 * sc, sp = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
                if (sp > cap) { p.vx *= cap / sp; p.vy *= cap / sp; }
                p.x += p.vx * k; p.y += p.vy * k;
                if (p.x < -10 || p.x > this.w + 10 || p.y < -10 || p.y > this.h + 10) { this.spawnIon(p); }
            }
        }

        for (i = this.fx.length - 1; i >= 0; i--) {
            var f = this.fx[i];
            f.life += dt * (f.text ? 1.3 : 2);
            if (f.sparks) {
                for (j = 0; j < f.sparks.length; j++) {
                    var s = f.sparks[j];
                    s[0] += s[2] * k; s[1] += s[3] * k; s[2] *= 0.93; s[3] *= 0.93;
                }
            }
            if (f.life >= 1) { this.fx.splice(i, 1); }
        }
    };

    Arena.prototype.draw = function (t) {
        var c = this.ctx, i, g;
        c.clearRect(0, 0, this.w, this.h);

        if (this.auto) {
            // The unit and its vents, so it is plain where the ions come from.
            for (i = 0; i < this.emitters.length; i++) {
                var e = this.emitters[i], ex = e[0] * this.w, ey = e[1] * this.h;
                var halo = c.createRadialGradient(ex, ey, 0, ex, ey, 46);
                halo.addColorStop(0, 'rgba(' + GOLD + ',' + (0.22 + 0.08 * Math.sin(t * 3 + i)) + ')');
                halo.addColorStop(1, 'rgba(' + GOLD + ',0)');
                c.fillStyle = halo;
                c.beginPath(); c.arc(ex, ey, 46, 0, 6.283); c.fill();
                c.strokeStyle = 'rgba(' + GOLD + ',0.55)';
                c.lineWidth = 1;
                if (i === 0) {
                    c.strokeRect(ex - 22, ey - 10, 44, 14);
                } else {
                    c.strokeRect(ex - 5, ey - 16, 10, 32);
                }
            }
        }

        // Germs: a body, a ring of spikes, a darker core.
        for (i = 0; i < this.germs.length; i++) {
            g = this.germs[i];
            var r = this.radius(g), fade = g.dying ? 1 - g.dying : 1;
            var gx = g.x * this.w, gy = g.y * this.h, spin = g.spin + t * 0.8;
            r *= g.dying ? 1 - g.dying * 0.6 : 1;

            c.strokeStyle = 'rgba(' + GERM + ',' + (0.8 * fade) + ')';
            c.lineWidth = Math.max(1.2, r * 0.12);
            c.beginPath();
            for (var sp = 0; sp < 9; sp++) {
                var a = spin + sp * 0.698;
                c.moveTo(gx + Math.cos(a) * r * 0.9, gy + Math.sin(a) * r * 0.9);
                c.lineTo(gx + Math.cos(a) * r * 1.35, gy + Math.sin(a) * r * 1.35);
            }
            c.stroke();

            var body = c.createRadialGradient(gx - r * 0.3, gy - r * 0.3, r * 0.1, gx, gy, r);
            body.addColorStop(0, 'rgba(' + GERM + ',' + (0.95 * fade) + ')');
            body.addColorStop(1, 'rgba(' + GERM + ',' + (0.45 * fade) + ')');
            c.fillStyle = body;
            c.beginPath(); c.arc(gx, gy, r, 0, 6.283); c.fill();

            // About to split: a pulsing ring warns the visitor.
            if (!g.dying && g.split - g.age < 1) {
                c.strokeStyle = 'rgba(' + GERM + ',' + (0.5 + 0.5 * Math.sin(t * 18)) + ')';
                c.lineWidth = 1.5;
                c.beginPath(); c.arc(gx, gy, r * 1.6, 0, 6.283); c.stroke();
            }
        }

        c.globalCompositeOperation = 'lighter';
        if (this.auto) {
            c.lineCap = 'round';
            for (i = 0; i < this.ions.length; i++) {
                var p = this.ions[i], life = Math.min(1, p.age * 4, (p.life - p.age) * 2);
                c.strokeStyle = 'rgba(' + GOLD + ',' + (0.35 * life) + ')';
                c.lineWidth = p.s;
                c.beginPath(); c.moveTo(p.x, p.y); c.lineTo(p.x - p.vx * 2.5, p.y - p.vy * 2.5); c.stroke();
                c.fillStyle = 'rgba(' + GOLD + ',' + (0.85 * life) + ')';
                c.beginPath(); c.arc(p.x, p.y, p.s, 0, 6.283); c.fill();
            }
        }

        for (i = 0; i < this.fx.length; i++) {
            var f = this.fx[i], a2 = 1 - f.life;
            if (f.text) {
                c.fillStyle = 'rgba(' + f.rgb + ',' + a2 + ')';
                c.font = '600 13px Inter, system-ui, sans-serif';
                c.textAlign = 'center';
                c.fillText(f.text, f.x, f.y - f.life * 26);
                continue;
            }
            c.strokeStyle = 'rgba(' + f.rgb + ',' + (0.6 * a2) + ')';
            c.lineWidth = 1.4;
            c.beginPath(); c.arc(f.x, f.y, 6 + f.life * 30, 0, 6.283); c.stroke();
            c.fillStyle = 'rgba(' + f.rgb + ',' + (0.9 * a2) + ')';
            for (var s2 = 0; s2 < f.sparks.length; s2++) {
                c.beginPath(); c.arc(f.sparks[s2][0], f.sparks[s2][1], 1.5, 0, 6.283); c.fill();
            }
        }
        c.globalCompositeOperation = 'source-over';
    };

    // The visitor's tap: the nearest germ within reach. Reach is generous —
    // a fingertip is not a cursor — but it is still one germ per tap.
    Arena.prototype.tap = function (px, py) {
        var best = null, bd = Infinity;
        for (var i = 0; i < this.germs.length; i++) {
            var g = this.germs[i];
            if (g.dying) { continue; }
            var dx = g.x * this.w - px, dy = g.y * this.h - py, d2 = dx * dx + dy * dy;
            var reach = Math.max(26, this.radius(g) * 1.7);
            if (d2 < reach * reach && d2 < bd) { bd = d2; best = g; }
        }
        if (best) { this.kill(best, true); return true; }
        this.fx.push({ x: px, y: py, life: 0.35, sparks: [], rgb: INK });   // a miss: just the cloth
        return false;
    };

    /* ---- Game ------------------------------------------------------------- */
    var pro = new Arena($('cg-pro'), true);
    var you = new Arena($('cg-you'), false);

    var state = 'intro', clock = 0, plan = [], next = 0, count = 0, last = 0, raf = 0;

    function show(panel) {
        overlay.hidden = panel === null;
        var panels = overlay.querySelectorAll('[data-panel]');
        for (var i = 0; i < panels.length; i++) { panels[i].hidden = panels[i].dataset.panel !== panel; }
        board.classList.toggle('is-playing', panel === null);
    }

    function hud() {
        $('cg-pro-score').textContent = pro.score;
        $('cg-you-score').textContent = you.score;
        $('cg-pro-load').style.width = Math.min(100, pro.alive() / 24 * 100) + '%';
        $('cg-you-load').style.width = Math.min(100, you.alive() / 24 * 100) + '%';
        timeEl.textContent = Math.max(0, Math.ceil(ROUND - clock));
    }

    function begin() {
        pro.reset(); you.reset();
        plan = schedule((Math.random() * 1e9) | 0);
        next = 0; clock = 0; count = 3;
        state = 'count';
        $('cg-count').textContent = '3';
        show('count');
        hud();
    }

    function finish() {
        state = 'result';
        var p = pro.score, y = you.score, lp = pro.alive(), ly = you.alive();
        $('cg-final-pro').textContent = p;
        $('cg-final-you').textContent = y;
        $('cg-left-pro').textContent = lp === 1 ? '1 germ left' : lp + ' germs left';
        $('cg-left-you').textContent = ly === 1 ? '1 germ left' : ly + ' germs left';

        // Keeping up means the surface, not the points: germs left alone split,
        // so a fast tapper can rack up points while losing the room. The
        // winner is whoever leaves fewer germs behind.
        var verdict, summary;
        if (ly > lp) {
            verdict = 'The PRO wins.';
            summary = 'You wiped ' + (y / POINTS) + ' germs by hand and still left ' + ly + ' behind '
                + '(the PRO left ' + lp + '). It cleared ' + (p / POINTS) + ' without anyone lifting a '
                + 'finger — and it doesn’t stop when the thirty seconds do.';
        } else {
            verdict = 'You kept up — this time.';
            summary = 'Impressive. Now picture doing that all day, in every room, every day. '
                + 'The PRO does its side without anyone lifting a finger.';
        }
        $('cg-verdict').textContent = verdict;
        $('cg-summary').textContent = summary;
        show('result');
    }

    function frame(now) {
        var dt = Math.min(0.05, (now - last) / 1000 || 0.016);
        last = now;

        if (state === 'count') {
            count -= dt;
            if (count <= 0) { state = 'play'; show(null); }
            else { $('cg-count').textContent = Math.ceil(count); }
        }

        if (state === 'play') {
            clock += dt;
            while (next < plan.length && plan[next].t <= clock) {
                var s = plan[next++];
                pro.add(s.x, s.y, s.s);
                you.add(s.x, s.y, s.s);
            }
            if (clock >= ROUND) { finish(); }
            hud();
        }

        var playing = state === 'play';
        pro.step(dt, playing); you.step(dt, playing);
        pro.draw(now / 1000); you.draw(now / 1000);
        raf = requestAnimationFrame(frame);
    }

    you.canvas.addEventListener('pointerdown', function (e) {
        if (state !== 'play') { return; }
        e.preventDefault();
        var r = you.canvas.getBoundingClientRect();
        you.tap(e.clientX - r.left, e.clientY - r.top);
        hud();
    });

    $('cg-start').addEventListener('click', begin);
    $('cg-again').addEventListener('click', begin);

    var rt;
    window.addEventListener('resize', function () {
        clearTimeout(rt);
        rt = setTimeout(function () { pro.resize(); you.resize(); }, 120);
    });

    // A hidden tab freezes the round rather than letting the germs win it.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { cancelAnimationFrame(raf); raf = 0; }
        else if (!raf) { last = performance.now(); raf = requestAnimationFrame(frame); }
    });

    show('intro');
    hud();
    last = performance.now();
    raf = requestAnimationFrame(frame);
})();
