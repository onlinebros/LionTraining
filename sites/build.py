#!/usr/bin/env python3
"""Build one of the public sites in sites/ into its dist/.

    python3 ../build.py             live build; stops if any business detail is still TODO
    python3 ../build.py --preview   preview build; TODOs are highlighted, pages are noindex
    python3 ../build.py --site q3.life        build a named site from anywhere

One back office, one or more front doors. Today there is just q3.life, the
product-first company site. The builder takes a site directory rather than
assuming one so that a second front door — a campaign landing site, a separate
B2B domain — is a directory and a site.json, not a fork of this file.

A site directory holds:

    site.json       every business fact the site prints
    src/layout.html the shared page shell
    src/pages/*.html each starting with a <!--meta {...}--> JSON header
    src/assets/     its own styles and images
    dist/           the build output (generated; not in git)

Assets are layered: sites/_shared/assets/ is copied first and the site's own
src/assets/ over the top, so the fonts and the referral script exist once for
every site while each keeps its own stylesheet and logo. A site that wants a
different ref.js simply ships one — the overlay is last-wins.

Template tokens:
    {{ contact.email }}         value from site.json, HTML-escaped
    {{ attr:contact.email }}    same, for use inside an attribute (a TODO renders as "#")
    {{ tel:contact.phone }}     a tel: URL with the formatting stripped
    {{ asset:site.css }}        assets/site.css?v=<content hash>, so neither Cloudflare
                                nor a browser serves a stale file after a deploy

Standard library only: no install step on any machine that has Python 3.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import html
import json
import re
import shutil
import sys
from pathlib import Path

SITES = Path(__file__).resolve().parent
SHARED_ASSETS = SITES / "_shared" / "assets"

TODO = "TODO:"
TOKEN = re.compile(r"\{\{\s*(?:(asset|attr|tel):)?([\w./-]+)\s*\}\}")
META = re.compile(r"\A\s*<!--meta\s+(\{.*?\})\s*-->\s*", re.S)
LINK = re.compile(r'<(?:a|link|img|script)\b[^>]*?\s(?:href|src)="([^"]*)"')


def load_facts(root: Path, api_url: str | None = None) -> dict:
    facts = json.loads((root / "site.json").read_text())
    # The contact form, the sponsor lookup and the Log in / Join links all go to
    # the member app. Only where they point changes per environment; the member
    # platform URL printed on the pages never does.
    app_base = (api_url or facts["site"]["app_url"]).rstrip("/")
    facts["site"]["app_base"] = app_base
    facts["support"]["endpoint"] = app_base + facts["support"]["endpoint_path"]

    # Which business line this front door sells, and the host it sells it from.
    # ref.js puts both on every Join link so the back office records which site
    # a partner came in through — see App\Services\Opportunities\
    # OpportunityTracker. A site that omits the block is the default line.
    facts.setdefault("opportunity", {})
    facts["opportunity"].setdefault("key", "")
    facts["opportunity"].setdefault(
        "site", facts["site"]["url"].split("//", 1)[-1].rstrip("/"))

    # The paid subscription. Called `program` because it is a Training Program
    # and not a membership: a member account is free (owner, 2026-09-22).
    m = facts["program"]
    m["price_display"] = f"${m['amount_cents'] / 100:,.2f} {m['currency'].upper()}"
    m["interval_phrase"], m["interval_adverb"], m["interval_noun"] = {
        "year": ("per year", "yearly", "year"),
        "month": ("per month", "monthly", "month"),
    }[m["interval"]]
    updated = dt.date.fromisoformat(facts["policies"]["updated"])
    facts["policies"]["updated_display"] = f"{updated:%B} {updated.day}, {updated.year}"
    facts["year"] = str(dt.date.today().year)
    return facts


def lookup(facts: dict, path: str) -> str:
    node = facts
    for part in path.split("."):
        if not isinstance(node, dict) or part not in node:
            raise KeyError(path)
        node = node[part]
    if isinstance(node, (dict, list)):
        raise KeyError(path)
    return str(node)


class Renderer:
    def __init__(self, facts: dict):
        self.facts = facts
        self.missing: dict[str, str] = {}
        self.assets: dict[str, str] = {}

    def render(self, text: str, where: str, ctx: dict | None = None, plain: bool = False) -> str:
        ctx = ctx or {}

        def sub(m: re.Match) -> str:
            kind, path = m.group(1), m.group(2)
            if kind == "asset":
                if path not in self.assets:
                    sys.exit(f"{where}: no such asset '{path}'")
                return self.assets[path]
            if path in ctx:
                return ctx[path]
            try:
                value = lookup(self.facts, path)
            except KeyError:
                sys.exit(f"{where}: unknown field '{path}'")
            if value.startswith(TODO):
                label = value[len(TODO):].strip()
                self.missing.setdefault(path, label)
                if kind in ("attr", "tel"):
                    return "#"
                if plain:
                    return html.escape(f"[{label}]")
                return f'<mark class="todo" title="{html.escape(path)}">[{html.escape(label)}]</mark>'
            if kind == "tel":
                return "tel:" + re.sub(r"[^\d+]", "", value)
            return html.escape(value)

        return TOKEN.sub(sub, text)


def check_links(pages: dict[str, str], dist: Path) -> list[str]:
    """Every relative link must land on a built page or asset, and every #fragment on an id."""
    broken = []
    for slug, doc in pages.items():
        for ref in LINK.findall(doc):
            if re.match(r"[a-z][a-z0-9+.-]*:|#|//", ref):  # absolute URLs, mailto:, tel:, TODO "#"
                continue
            target, _, fragment = ref.split("?", 1)[0].partition("#")
            target = "index" if target in ("", "./") else target
            if not ((dist / target).is_file() or (dist / f"{target}.html").is_file()):
                broken.append(f"{slug}.html -> {ref}")
            elif fragment and f'id="{fragment}"' not in pages.get(target, ""):
                broken.append(f"{slug}.html -> {ref} (no such id)")
    return broken


def copy_assets(src: Path, dest: Path) -> None:
    """Shared assets first, the site's own over the top. Last wins."""
    dest.mkdir(parents=True, exist_ok=True)
    for source in (SHARED_ASSETS, src):
        if source.is_dir():
            shutil.copytree(source, dest, dirs_exist_ok=True)


def resolve_site(arg: str | None) -> Path:
    """The site directory: --site, else the current one, else fail with the list."""
    candidates = [] if arg is None else [Path(arg), SITES / arg]
    candidates.append(Path.cwd())

    for path in candidates:
        if (path / "site.json").is_file():
            return path.resolve()

    known = sorted(p.name for p in SITES.iterdir() if (p / "site.json").is_file())
    sys.exit(f"No site.json found. Pass --site with one of: {', '.join(known) or '(none)'}")


def main() -> int:
    ap = argparse.ArgumentParser(description="Build one of the sites in sites/ into its dist/.")
    ap.add_argument("--site", help="site directory, or its name under sites/ "
                                   "(default: the current directory)")
    ap.add_argument("--preview", action="store_true",
                    help="highlight unfilled details and keep search engines out")
    ap.add_argument("--base", help="URL path the site is served from "
                                   "(default: / for live, /preview/ for preview)")
    ap.add_argument("--api-url", help="app URL the contact form posts to, e.g. the dev server "
                                      "(default: site.app_url in site.json)")
    ap.add_argument("--set", action="append", default=[], metavar="KEY=VALUE",
                    help="override one site.json value for this build only, "
                         "e.g. support.turnstile_site_key=... (repeatable)")
    args = ap.parse_args()

    root = resolve_site(args.site)
    src, dist = root / "src", root / "dist"
    base = args.base or ("/preview/" if args.preview else "/")

    facts = load_facts(root, args.api_url)
    for item in args.set:
        path, sep, value = item.partition("=")
        *parents, leaf = path.split(".")
        node = facts
        for part in parents:
            node = node.get(part) if isinstance(node, dict) else None
        if not sep or not isinstance(node, dict) or leaf not in node:
            sys.exit(f"--set {item}: expected KEY=VALUE for an existing site.json field")
        node[leaf] = value

    r = Renderer(facts)

    # Staged outside dist/ and moved in at the end. The asset hashes have to be
    # known before the pages render, but a build that then refuses must not have
    # replaced a good dist/ with half of a bad one — deploy.sh publishes that
    # directory.
    staging = root / ".build"
    shutil.rmtree(staging, ignore_errors=True)
    staged = staging / "assets"
    copy_assets(src / "assets", staged)
    for f in sorted(staged.rglob("*")):
        if f.is_file():
            rel = f.relative_to(staged).as_posix()
            r.assets[rel] = f"assets/{rel}?v={hashlib.sha256(f.read_bytes()).hexdigest()[:10]}"

    layout = (src / "layout.html").read_text()
    site_url = facts["site"]["url"].rstrip("/")
    # What every page but the home page is suffixed with. Defaults to
    # "Brand (Short)", which is what q3.life has always printed.
    title_suffix = facts["site"].get(
        "title_suffix", f"{facts['site']['brand']} ({facts['site']['brand_short']})")
    banner = ('<div class="preview-banner" role="note">Preview build. Highlighted items are details '
              'still needed before this site goes live.</div>') if args.preview else ""

    pages: dict[str, str] = {}
    for page in sorted((src / "pages").glob("*.html")):
        slug, raw = page.stem, page.read_text()
        m = META.match(raw)
        if not m:
            sys.exit(f"{page.name}: missing <!--meta {{...}}--> header")
        meta = json.loads(m.group(1))
        title = r.render(meta["title"], page.name, plain=True)
        if slug != "index":
            title = f"{title} · {html.escape(title_suffix)}"
        path = "" if slug == "index" else slug
        ctx = {
            "page.title": title,
            "page.description": r.render(meta["description"], page.name, plain=True),
            "page.slug": slug,
            "page.path": path,
            "base": base,
            "canonical": f"{site_url}/{path}",
            "robots": "noindex, nofollow" if args.preview or slug == "404" else "index, follow",
            "preview_banner": banner,
            "content": r.render(raw[m.end():], page.name),
        }
        doc = r.render(layout, f"layout.html ({page.name})", ctx)
        if meta.get("nav"):
            doc = doc.replace(f'data-nav="{meta["nav"]}"', f'data-nav="{meta["nav"]}" aria-current="page"')
        pages[slug] = doc

    def list_missing(stream):
        for key, label in r.missing.items():
            print(f"  {key:32} {label}", file=stream)

    if r.missing and not args.preview:
        print(f"Live build stopped: {len(r.missing)} details in site.json are still TODO:", file=sys.stderr)
        list_missing(sys.stderr)
        print("\nFill them in, or build with --preview.", file=sys.stderr)
        shutil.rmtree(staging, ignore_errors=True)
        return 1

    shutil.rmtree(dist, ignore_errors=True)
    staging.rename(dist)

    for slug, doc in pages.items():
        (dist / f"{slug}.html").write_text(doc)

    entries = "\n".join(
        f"  <url><loc>{site_url}/{'' if s == 'index' else s}</loc>"
        f"<lastmod>{facts['policies']['updated']}</lastmod></url>"
        for s in pages if s != "404")
    (dist / "sitemap.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        f'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{entries}\n</urlset>\n')
    (dist / "robots.txt").write_text(
        "User-agent: *\nDisallow: /\n" if args.preview
        else f"User-agent: *\nAllow: /\n\nSitemap: {site_url}/sitemap.xml\n")

    broken = check_links(pages, dist)
    if broken:
        print("Broken links:", *broken, sep="\n  ", file=sys.stderr)
        return 1

    print(f"Built {len(pages)} pages into {root.name}/dist/ "
          f"({'preview' if args.preview else 'live'}, base {base})")
    if r.missing:
        print(f"\n{len(r.missing)} details still TODO (highlighted in the preview):")
        list_missing(sys.stdout)
    if facts.get("_confirm"):
        print("\nPrinted on the site from app config, not yet confirmed by the business:")
        for item in facts["_confirm"]:
            print(f"  - {item}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
