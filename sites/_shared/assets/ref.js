/* A partner's personal copy of the site: SITE/CODE (or ?ref=CODE).
 *
 * nginx serves the home page for /CODE. This reads the code, asks the member
 * app whose it is, and turns on everything marked data-ref: the "invited by"
 * bar and the Join buttons, which go to {app}/join/CODE. data-ref-hide is the
 * opposite, for what a visitor without a code sees instead.
 *
 * The code is remembered for 30 days, so it survives clicking around the site
 * and coming back later. A newer link replaces it. Without a code nothing
 * changes: sign-up is invitation only, so the plain site offers no Join.
 *
 * Shared by every site in sites/ — see sites/build.py. Each one declares on
 * <body> which business line it sells and which host it is, and both ride along
 * on the Join link:
 *
 *     {app}/join/CODE?o=plasmaguard&s=q3.life
 *
 * That is the only place the difference between the front doors can be
 * recorded, because they all post the same sign-up form. The back office reads
 * the pair in App\Services\Opportunities\OpportunityTracker and it decides,
 * among other things, whether a card is asked for at all. A site that declares
 * neither behaves exactly as before.
 *
 * data-buy is the same idea for selling rather than recruiting: it points at
 * the partner's own storefront for the vendor product this site sells,
 *
 *     {app}/p/CODE/plasmaguard/pro-in-duct
 *
 * which takes the address, computes the vendor's tax and charges their Stripe
 * account (App\Http\Controllers\VendorStorefrontController). The vendor and
 * product keys are declared on <body> and must match backend/config/vendors.php.
 * Without a code there is no partner to credit the sale to, so a data-buy link
 * is left as authored — inside data-ref it is not shown at all.
 */
(function () {
  var KEY = 'q3.ref';
  var DAYS = 30;
  var CODE = /^[A-Za-z0-9]{8}$/;
  var app = document.body.getAttribute('data-app');
  var opportunity = document.body.getAttribute('data-opportunity');
  var entrySite = document.body.getAttribute('data-site');
  var buyVendor = document.body.getAttribute('data-buy-vendor');
  var buyProduct = document.body.getAttribute('data-buy-product');

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

  /* {app}/join/CODE, plus which door this is. Built with URLSearchParams so a
   * hostname or key with anything awkward in it cannot break out of the query
   * string. */
  function joinUrl(code) {
    var url = app + '/join/' + encodeURIComponent(code);
    var params = new URLSearchParams();
    if (opportunity) params.set('o', opportunity);
    if (entrySite) params.set('s', entrySite);
    var query = params.toString();
    return query ? url + '?' + query : url;
  }

  /* {app}/p/CODE/vendor/product — the partner's own storefront, where the
   * order is actually placed. Only wired when this site declares what it
   * sells; a site that sells nothing leaves its data-buy links alone. */
  function buyUrl(code) {
    if (!buyVendor || !buyProduct) return null;
    return app + '/p/' + encodeURIComponent(code)
      + '/' + encodeURIComponent(buyVendor)
      + '/' + encodeURIComponent(buyProduct);
  }

  function show(s) {
    var join = joinUrl(s.code);
    var buy = buyUrl(s.code);
    each('[data-join]', function (a) { a.href = join; });
    if (buy) each('[data-buy]', function (a) { a.href = buy; });
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
