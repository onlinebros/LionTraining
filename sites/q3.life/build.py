#!/usr/bin/env python3
"""Build the q3.life site into dist/.

    python3 build.py             live build; stops if any business detail is still TODO
    python3 build.py --preview   preview build; TODOs are highlighted, pages are noindex

Pages live in src/pages/. Each starts with a <!--meta {...}--> JSON header and
is wrapped in src/layout.html. Every business fact the site prints (entity,
address, price, policy windows) comes from site.json, so a fact changes in one
place and the pages can never disagree with each other.

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

ROOT = Path(__file__).resolve().parent
SRC = ROOT / "src"
DIST = ROOT / "dist"

TODO = "TODO:"
TOKEN = re.compile(r"\{\{\s*(?:(asset|attr|tel):)?([\w./-]+)\s*\}\}")
META = re.compile(r"\A\s*<!--meta\s+(\{.*?\})\s*-->\s*", re.S)
LINK = re.compile(r'<(?:a|link|img|script)\b[^>]*?\s(?:href|src)="([^"]*)"')


def load_facts(api_url: str | None = None) -> dict:
    facts = json.loads((ROOT / "site.json").read_text())
    # The contact form, the sponsor lookup and the Log in / Join links all go to
    # the member app. Only where they point changes per environment; the member
    # platform URL printed on the pages never does.
    app_base = (api_url or facts["site"]["app_url"]).rstrip("/")
    facts["site"]["app_base"] = app_base
    facts["support"]["endpoint"] = app_base + facts["support"]["endpoint_path"]
    m = facts["membership"]
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


def check_links(pages: dict[str, str]) -> list[str]:
    """Every relative link must land on a built page or asset, and every #fragment on an id."""
    broken = []
    for slug, doc in pages.items():
        for ref in LINK.findall(doc):
            if re.match(r"[a-z][a-z0-9+.-]*:|#|//", ref):  # absolute URLs, mailto:, tel:, TODO "#"
                continue
            target, _, fragment = ref.split("?", 1)[0].partition("#")
            target = "index" if target in ("", "./") else target
            if not ((DIST / target).is_file() or (DIST / f"{target}.html").is_file()):
                broken.append(f"{slug}.html -> {ref}")
            elif fragment and f'id="{fragment}"' not in pages.get(target, ""):
                broken.append(f"{slug}.html -> {ref} (no such id)")
    return broken


def main() -> int:
    ap = argparse.ArgumentParser(description="Build the q3.life site into dist/.")
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
    base = args.base or ("/preview/" if args.preview else "/")

    facts = load_facts(args.api_url)
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
    for f in sorted((SRC / "assets").rglob("*")):
        if f.is_file():
            rel = f.relative_to(SRC / "assets").as_posix()
            r.assets[rel] = f"assets/{rel}?v={hashlib.sha256(f.read_bytes()).hexdigest()[:10]}"

    layout = (SRC / "layout.html").read_text()
    site_url = facts["site"]["url"].rstrip("/")
    banner = ('<div class="preview-banner" role="note">Preview build. Highlighted items are details '
              'still needed before this site goes live.</div>') if args.preview else ""

    pages: dict[str, str] = {}
    for page in sorted((SRC / "pages").glob("*.html")):
        slug, raw = page.stem, page.read_text()
        m = META.match(raw)
        if not m:
            sys.exit(f"{page.name}: missing <!--meta {{...}}--> header")
        meta = json.loads(m.group(1))
        title = r.render(meta["title"], page.name, plain=True)
        if slug != "index":
            title = f"{title} · {html.escape(facts['site']['brand'])} (Q3)"
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
        return 1

    if DIST.exists():
        shutil.rmtree(DIST)
    shutil.copytree(SRC / "assets", DIST / "assets")
    for slug, doc in pages.items():
        (DIST / f"{slug}.html").write_text(doc)

    entries = "\n".join(
        f"  <url><loc>{site_url}/{'' if s == 'index' else s}</loc>"
        f"<lastmod>{facts['policies']['updated']}</lastmod></url>"
        for s in pages if s != "404")
    (DIST / "sitemap.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        f'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{entries}\n</urlset>\n')
    (DIST / "robots.txt").write_text(
        "User-agent: *\nDisallow: /\n" if args.preview
        else f"User-agent: *\nAllow: /\n\nSitemap: {site_url}/sitemap.xml\n")

    broken = check_links(pages)
    if broken:
        print("Broken links:", *broken, sep="\n  ", file=sys.stderr)
        return 1

    print(f"Built {len(pages)} pages into dist/ ({'preview' if args.preview else 'live'}, base {base})")
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
