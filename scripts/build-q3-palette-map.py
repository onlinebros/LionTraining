#!/usr/bin/env python3
"""
Generate assets/css/q3-palette-map.css from the Cuba template stylesheet.

The Cuba theme hardcodes its dark-mode palette (#1d1e26 page, #262932 card,
#374558 border, ...) and its brand purple (#7366ff) in ~1,800 rules. Rather
than hand-chasing those colours component by component, this script re-emits
the *same selectors* with the colours swapped for Q3 theme tokens, so every
Cuba component — tables, modals, dropdowns, datatables, tabs, wizards — lands
on the Q3 palette without touching any markup.

Run after upgrading the Cuba template:

    python3 scripts/build-q3-palette-map.py

Hand-written theme rules live in assets/css/q3-theme.css, which loads *after*
this file and therefore always wins.
"""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "assets" / "css" / "style.css"
TARGET = ROOT / "assets" / "css" / "q3-palette-map.css"

# ---------------------------------------------------------------------------
# Colour maps
# ---------------------------------------------------------------------------

# Applied to every rule: brand + state colours, wherever they appear.
GLOBAL_MAP = {
    "#7366ff": "#d4af37",  # Cuba brand purple  -> Q3 primary gold
    "#5f4dff": "#c69b3c",
    "#563dd9": "#a8842c",
    "#65c15c": "#3e9e6e",  # success            -> muted emerald
    "#54ba4a": "#3e9e6e",
    "#40b8f5": "#7c8a93",  # info               -> muted slate (no bright blue)
    "#16c7f9": "#7c8a93",
    "#fc564a": "#b4483f",  # danger             -> restrained deep red
    "#ffb829": "#c99a3e",  # warning            -> antique gold
    "#ffa941": "#c99a3e",
    "#838383": "#8a867e",  # secondary          -> warm grey
    # Hover/active shades of the same brand + state colours.
    "#6e61ff": "#c69b3c",
    "#ffb624": "#c99a3e",
    "#3bb6f5": "#7c8a93",
}

# rgb()/rgba() spellings of the same brand colours.
GLOBAL_RGB_MAP = {
    "115,102,255": "212,175,55",
    "84,186,74": "62,158,110",
    "252,86,74": "180,72,63",
    "255,169,65": "201,154,62",
    "255,184,41": "201,154,62",
    "64,184,245": "124,138,147",
}

# Applied only inside `.dark-only` rules: Cuba's dark surface/border/text ramp.
DARK_MAP = {
    "#1d1e26": "#0b0b0c",  # page background    -> Q3 near-black
    "#262932": "#141416",  # card / panel       -> Q3 charcoal
    "#1f2533": "#131316",
    "#1c212b": "#101012",
    "#22262f": "#161619",
    "#191e27": "#0f0f11",
    "#1c1d26": "#0f0f11",
    "#1f232b": "#131315",
    "#202128": "#1a1a1d",
    "#2b2b2b": "#151517",
    "#2e3543": "#1c1710",  # "light shade primary" -> warm gold-tinted charcoal
    "#323846": "#1f1f23",
    "#3f475a": "#33333a",
    "#374558": "#2a2a30",  # border ramp        -> neutral dark border
    "#374657": "#2a2a30",
    "#404040": "#2a2a2f",
    "#656571": "#7d7a74",
    "#98a6ad": "#a9a6a0",  # muted text         -> Q3 secondary text
    "#f4f4f4": "#f5f1e8",
    "#fcfcfd": "#141416",
    "#2c2c45d4": "#2a2a30",
    # Bootstrap's contextual "light" component fills (alert-light, badge and
    # alert variants). Cuba keeps these pale even in dark mode, which leaves
    # bright bars sitting on the Q3 charcoal.
    "#f1f1f1": "#1a1a1d",
    "#fdfdfe": "#141416",
    "#c3c8d5": "#33333a",
    "#b1b1b2": "#33333a",
    "#c6c8ca": "#33333a",
    "#d6d8db": "#33333a",
    "#b8daff": "#3a4247",  # info border
    "#bee5eb": "#3a4247",
    "#c3e6cb": "#22422f",  # success border
    "#f5c6cb": "#452321",  # danger border
    "#ffeeba": "#3d3116",  # warning border
}

# Same hexes, but read as TEXT rather than as a surface.
#
# Cuba's dark mode keeps a few contextual components (alert-light, .table-light
# rows, badges) on a pale fill and therefore sets near-black text on them. Once
# DARK_MAP flips those fills to Q3 charcoal, mapping the text by the same table
# would paint dark-on-dark. So for colour properties the surface ramp inverts
# to the text ramp instead. Takes precedence over DARK_MAP.
DARK_TEXT_ROLE_MAP = {
    "#1d1e26": "#f5f1e8",
    "#262932": "#e6e2d9",
    "#1f2533": "#e6e2d9",
    "#1c212b": "#e6e2d9",
    "#22262f": "#e6e2d9",
    "#191e27": "#e6e2d9",
    "#1c1d26": "#e6e2d9",
    "#1f232b": "#e6e2d9",
    "#202128": "#d8d4cc",
    "#2b2b2b": "#d8d4cc",
    "#2e3543": "#d8d4cc",
    "#323846": "#a9a6a0",
    "#3f475a": "#a9a6a0",
    "#374558": "#a9a6a0",
    "#374657": "#a9a6a0",
    "#404040": "#d8d4cc",
}

COLOUR_PROPS = ("color", "-webkit-text-fill-color")

# Cuba writes its dark body text as translucent white; Q3 uses warm off-white.
DARK_TEXT_MAP = {
    "hsla(0,0%,100%,.6)": "#d8d4cc",
    "hsla(0,0%,100%,.7)": "#e6e2d9",
    "hsla(0,0%,100%,.8)": "#f5f1e8",
    "hsla(0,0%,100%,.9)": "#f5f1e8",
    "hsla(0,0%,100%,.5)": "#a9a6a0",
    "hsla(0,0%,100%,.4)": "#8a867e",
    "hsla(0,0%,100%,.3)": "#6e6b66",
    "hsla(0,0%,100%,.2)": "#3a3a40",
    "hsla(0,0%,100%,.1)": "#26262b",
    # .alert-light / .badge-light fills, written as near-white hsla.
    "hsla(0,0%,96%,.8)": "#1a1a1d",
    "hsla(0,0%,96%,.9)": "#1a1a1d",
    "hsla(0,0%,96%,.4)": "#1a1a1d",
}

# `color: #fff` inside dark rules becomes the warm off-white. Only the `color`
# property is remapped — `#fff` as a background or fill is left alone so
# gold-on-black button/badge treatments stay under q3-theme.css's control.
WHITE_TEXT = "#f5f1e8"

HEX_RE = re.compile(r"#[0-9a-fA-F]{3,8}\b")


def strip_comments(css: str) -> str:
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def iter_rules(css: str):
    """Yield (at_rule_stack, selector, declaration_block) in source order."""
    stack: list[str] = []
    buf: list[str] = []
    i, n = 0, len(css)
    while i < n:
        ch = css[i]
        if ch == "{":
            prelude = "".join(buf).strip()
            buf = []
            if prelude.startswith("@"):
                stack.append(prelude)
                i += 1
                continue
            end = css.find("}", i)
            if end == -1:
                break
            yield tuple(stack), prelude, css[i + 1 : end]
            i = end + 1
            continue
        if ch == "}":
            if stack:
                stack.pop()
            buf = []
            i += 1
            continue
        buf.append(ch)
        i += 1


def remap_value(value: str, is_dark: bool, prop: str) -> str:
    out = value

    def sub_hex(match: re.Match) -> str:
        raw = match.group(0)
        low = raw.lower()
        if low in GLOBAL_MAP:
            return GLOBAL_MAP[low]
        if is_dark:
            if prop in COLOUR_PROPS:
                if low in DARK_TEXT_ROLE_MAP:
                    return DARK_TEXT_ROLE_MAP[low]
                if low in ("#fff", "#ffffff"):
                    return WHITE_TEXT
            if low in DARK_MAP:
                return DARK_MAP[low]
        return raw

    out = HEX_RE.sub(sub_hex, out)

    for rgb, replacement in GLOBAL_RGB_MAP.items():
        if rgb in out:
            out = out.replace(rgb, replacement)

    if is_dark:
        for hsla, replacement in DARK_TEXT_MAP.items():
            if hsla in out:
                out = out.replace(hsla, replacement)

    return out


def remap_block(body: str, is_dark: bool) -> str:
    """Return only the declarations whose colours actually changed."""
    kept: list[str] = []
    for decl in body.split(";"):
        if ":" not in decl:
            continue
        prop, _, value = decl.partition(":")
        prop_key = prop.strip().lower()
        new_value = remap_value(value, is_dark, prop_key)
        if new_value != value:
            kept.append(f"{prop.strip()}:{new_value.strip()}")
    return ";".join(kept)


def build() -> str:
    css = strip_comments(SOURCE.read_text(encoding="utf-8", errors="replace"))

    chunks: list[str] = []
    current_at: tuple[str, ...] | None = None
    open_at = 0
    seen: set[tuple[tuple[str, ...], str, str]] = set()
    rule_count = 0

    for at_stack, selector, body in iter_rules(css):
        if any(a.startswith(("@keyframes", "@-webkit-keyframes", "@font-face")) for a in at_stack):
            continue
        if not selector:
            continue

        is_dark = "dark-only" in selector
        mapped = remap_block(body, is_dark)
        if not mapped:
            continue

        key = (at_stack, selector, mapped)
        if key in seen:
            continue
        seen.add(key)

        if at_stack != current_at:
            chunks.append("}" * open_at)
            chunks.append("".join(f"{a}{{" for a in at_stack))
            current_at, open_at = at_stack, len(at_stack)

        chunks.append(f"{selector}{{{mapped}}}")
        rule_count += 1

    chunks.append("}" * open_at)

    header = (
        "/* ==========================================================================\n"
        "   Q3 palette map — GENERATED FILE, DO NOT EDIT BY HAND.\n"
        "\n"
        "   Re-maps the Cuba template's hardcoded dark palette and brand purple onto\n"
        "   the Q3 black-and-gold tokens, selector for selector.\n"
        "\n"
        "   Regenerate with:  python3 scripts/build-q3-palette-map.py\n"
        "   Hand-written overrides belong in assets/css/q3-theme.css (loads after).\n"
        f"   Rules: {rule_count}\n"
        "   ========================================================================== */\n"
    )
    return header + "\n".join(c for c in chunks if c) + "\n"


if __name__ == "__main__":
    output = build()
    TARGET.write_text(output, encoding="utf-8")
    print(f"wrote {TARGET.relative_to(ROOT)} ({len(output):,} bytes)")
