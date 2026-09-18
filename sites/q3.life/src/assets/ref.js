/* A partner's personal copy of the site: q3.life/CODE (or ?ref=CODE).
 *
 * nginx serves the home page for /CODE. This reads the code, asks the member
 * app whose it is, and turns on everything marked data-ref: the "invited by"
 * bar and the Join buttons, which go to {app}/join/CODE. data-ref-hide is the
 * opposite, for what a visitor without a code sees instead.
 *
 * The code is remembered for 30 days, so it survives clicking around the site
 * and coming back later. A newer link replaces it. Without a code nothing
 * changes: sign-up is invitation only, so the plain site offers no Join.
 */
(function () {
  var KEY = 'q3.ref';
  var DAYS = 30;
  var CODE = /^[A-Za-z0-9]{8}$/;
  var app = document.body.getAttribute('data-app');

  function stored() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY));
      if (s && CODE.test(s.code) && Date.now() - s.at < DAYS * 864e5) return s;
    } catch (e) {}
    return null;
  }
  function remember(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
  function forget() { try { localStorage.removeItem(KEY); } catch (e) {} }

  function codeInUrl() {
    var q = new URLSearchParams(location.search).get('ref');
    if (q && CODE.test(q)) return q.toUpperCase();
    // Only the home page can be a /CODE address. A real page whose name
    // happens to be eight letters (/partners, /shipping) is served as itself.
    if (!document.body.classList.contains('page-index')) return null;
    var base = new URL(document.baseURI).pathname;
    var path = location.pathname;
    if (path.indexOf(base) !== 0) return null;
    var rest = path.slice(base.length).replace(/\/$/, '');
    return CODE.test(rest) ? rest.toUpperCase() : null;
  }

  function each(sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

  function show(s) {
    var join = app + '/join/' + encodeURIComponent(s.code);
    each('[data-join]', function (a) { a.href = join; });
    if (s.name) {
      each('[data-ref-name]', function (el) { el.textContent = s.name; });
      each('[data-ref-first]', function (el) { el.textContent = s.name.split(/\s+/)[0]; });
    }
    each('[data-ref]', function (el) { el.hidden = false; });
    each('[data-ref-hide]', function (el) { el.hidden = true; });
  }

  var fromUrl = codeInUrl();
  var known = stored();

  if (!fromUrl) {
    if (known) show(known);
    return;
  }
  if (!window.fetch) return;

  fetch(app + '/api/sponsors/' + fromUrl, { headers: { Accept: 'application/json' } })
    .then(function (res) {
      if (res.ok) return res.json().then(function (d) {
        var s = { code: d.code, name: d.name, at: Date.now() };
        remember(s);
        show(s);
      });
      // A dead code: drop it, and fall back to a link from earlier if any.
      if (res.status === 404) {
        if (known && known.code === fromUrl) forget();
        else if (known) show(known);
        return;
      }
      unconfirmed();
    })
    .catch(unconfirmed);

  // App unreachable or busy: the code is still the visitor's best route in.
  function unconfirmed() {
    show(known && known.code === fromUrl ? known : { code: fromUrl, name: null });
  }
})();
