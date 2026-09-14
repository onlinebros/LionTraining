#!/usr/bin/env python3
"""
Build every shipped Q3 logo asset from the full-resolution master.

assets/images/logo/q3_logo.png is the master (transparent PNG, ~0.8 MB). It is
never referenced by a view directly — everything the app serves is derived from
it here, so a new master means one command rather than a folder of hand-made files:

    q3_logo-web.png                full-res, palette-quantised — vendor pages
    q3_logo-sm.png                 screen-sized nav/auth mark
    q3-app-icon-192.png            favicon + collapsed sidebar mark (transparent)
    q3-app-icon-512.png            hi-res favicon (transparent)
    q3-apple-touch-icon-180.png    iOS home screen (on black)
    q3-pwa-icon-{192,512}.png      PWA install icon (on black)
    q3-pwa-maskable-{192,512}.png  Android adaptive icon (on black, safe-zone padded)

Run after replacing the master:

    python3 scripts/build-q3-logo-assets.py

The master is never modified, and aspect ratio is preserved exactly everywhere.

Requires no third-party imaging library (this box has neither Pillow nor
ImageMagick); PNG decode/encode is done here against the 8-bit RGBA,
non-interlaced subset the master uses. pngquant/optipng are shelled out to when
present — they are the only compression step, so the committed bytes match what
this script produces.
"""

from __future__ import annotations

import shutil
import struct
import subprocess
import sys
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOGO_DIR = ROOT / "assets" / "images" / "logo"
SOURCE = LOGO_DIR / "q3_logo.png"

# The nav and auth marks are height-constrained in CSS, never width-constrained
# (.q3-auth-logo caps at 74px tall, .q3-logo at 58px). Height is therefore the
# dimension that decides how crisp the mark looks; 4x the tallest render keeps
# it sharp on 3x phone screens with headroom for a future size bump.
SM_HEIGHT = 296

# Square icons carry the mark trimmed of its transparent margin and centred,
# scaled so its longest side covers `coverage` of the tile.
#
# Browser-only icons (favicon, collapsed sidebar mark) stay transparent so they
# sit on whatever is behind them. Anything a phone puts on its home screen is
# flattened onto the Q3 black instead: iOS and Android fill a transparent icon
# with white, which leaves the gold mark on a white tile.
#
# Maskable icons get a smaller mark. Android crops them to its own shape and
# only guarantees a centred circle 80% of the tile wide survives, so the mark's
# bounding box must fit inside that circle: 0.56 * sqrt(2) < 0.8.
ICON_COVERAGE = 0.74
MASKABLE_COVERAGE = 0.56
ICON_BACKGROUND = (0x05, 0x05, 0x05)  # the manifest's background_color / theme-color
ICONS = {
    # name: (size, coverage, background — None keeps it transparent)
    "q3-app-icon-192.png": (192, ICON_COVERAGE, None),
    "q3-app-icon-512.png": (512, ICON_COVERAGE, None),
    "q3-apple-touch-icon-180.png": (180, ICON_COVERAGE, ICON_BACKGROUND),
    "q3-pwa-icon-192.png": (192, ICON_COVERAGE, ICON_BACKGROUND),
    "q3-pwa-icon-512.png": (512, ICON_COVERAGE, ICON_BACKGROUND),
    "q3-pwa-maskable-192.png": (192, MASKABLE_COVERAGE, ICON_BACKGROUND),
    "q3-pwa-maskable-512.png": (512, MASKABLE_COVERAGE, ICON_BACKGROUND),
}


def read_chunks(data: bytes):
    assert data[:8] == b"\x89PNG\r\n\x1a\n", "not a PNG"
    pos = 8
    while pos < len(data):
        (length,) = struct.unpack(">I", data[pos : pos + 4])
        ctype = data[pos + 4 : pos + 8]
        body = data[pos + 8 : pos + 8 + length]
        yield ctype, body
        pos += 12 + length


def decode_rgba(data: bytes) -> tuple[int, int, bytearray]:
    header = None
    idat = bytearray()
    for ctype, body in read_chunks(data):
        if ctype == b"IHDR":
            header = struct.unpack(">IIBBBBB", body)
        elif ctype == b"IDAT":
            idat += body
        elif ctype == b"IEND":
            break

    width, height, depth, color, comp, filt, interlace = header
    if (depth, color, interlace) != (8, 6, 0):
        sys.exit(f"unsupported PNG: depth={depth} colour={color} interlace={interlace}")

    raw = zlib.decompress(bytes(idat))
    stride = width * 4
    out = bytearray(height * stride)
    prev = bytearray(stride)
    pos = 0

    for y in range(height):
        ftype = raw[pos]
        pos += 1
        line = bytearray(raw[pos : pos + stride])
        pos += stride

        if ftype == 1:  # Sub
            for i in range(4, stride):
                line[i] = (line[i] + line[i - 4]) & 0xFF
        elif ftype == 2:  # Up
            for i in range(stride):
                line[i] = (line[i] + prev[i]) & 0xFF
        elif ftype == 3:  # Average
            for i in range(stride):
                left = line[i - 4] if i >= 4 else 0
                line[i] = (line[i] + ((left + prev[i]) >> 1)) & 0xFF
        elif ftype == 4:  # Paeth
            for i in range(stride):
                a = line[i - 4] if i >= 4 else 0
                b = prev[i]
                c = prev[i - 4] if i >= 4 else 0
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                pred = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[i] = (line[i] + pred) & 0xFF
        elif ftype != 0:
            sys.exit(f"unsupported filter type {ftype}")

        out[y * stride : (y + 1) * stride] = line
        prev = line

    return width, height, out


def alpha_bbox(width: int, height: int, pixels: bytearray, threshold: int = 8):
    """Tight box around the visible mark, ignoring the master's transparent margin."""
    stride = width * 4
    min_x, min_y, max_x, max_y = width, height, -1, -1
    for y in range(height):
        base = y * stride
        for x in range(width):
            if pixels[base + x * 4 + 3] > threshold:
                if x < min_x:
                    min_x = x
                if x > max_x:
                    max_x = x
                if y < min_y:
                    min_y = y
                if y > max_y:
                    max_y = y
    if max_x < 0:
        sys.exit("master is fully transparent")
    return min_x, min_y, max_x, max_y


def crop(width: int, height: int, pixels: bytearray, box) -> tuple[int, int, bytearray]:
    min_x, min_y, max_x, max_y = box
    new_w, new_h = max_x - min_x + 1, max_y - min_y + 1
    stride, new_stride = width * 4, new_w * 4
    out = bytearray(new_h * new_stride)
    for y in range(new_h):
        src = (min_y + y) * stride + min_x * 4
        out[y * new_stride : (y + 1) * new_stride] = pixels[src : src + new_stride]
    return new_w, new_h, out


def downscale(width: int, height: int, pixels: bytearray, new_w: int):
    """Box filter, averaging in premultiplied alpha so edges don't fringe."""
    new_h = max(1, round(height * new_w / width))
    stride = width * 4
    out = bytearray(new_h * new_w * 4)

    y_edges = [round(i * height / new_h) for i in range(new_h + 1)]
    x_edges = [round(i * width / new_w) for i in range(new_w + 1)]

    for oy in range(new_h):
        y0, y1 = y_edges[oy], max(y_edges[oy] + 1, y_edges[oy + 1])
        for ox in range(new_w):
            x0, x1 = x_edges[ox], max(x_edges[ox] + 1, x_edges[ox + 1])
            r = g = b = a = 0
            count = 0
            for y in range(y0, y1):
                base = y * stride
                for x in range(x0, x1):
                    i = base + x * 4
                    alpha = pixels[i + 3]
                    r += pixels[i] * alpha
                    g += pixels[i + 1] * alpha
                    b += pixels[i + 2] * alpha
                    a += alpha
                    count += 1
            o = (oy * new_w + ox) * 4
            if a:
                out[o] = min(255, r // a)
                out[o + 1] = min(255, g // a)
                out[o + 2] = min(255, b // a)
                out[o + 3] = a // count
            # else: fully transparent, leave the zeroed pixel

    return new_w, new_h, out


def centre_on_square(width: int, height: int, pixels: bytearray, size: int):
    """Paste the mark into a transparent size x size tile, centred."""
    out = bytearray(size * size * 4)
    off_x, off_y = (size - width) // 2, (size - height) // 2
    stride, sq_stride = width * 4, size * 4
    for y in range(height):
        dst = (off_y + y) * sq_stride + off_x * 4
        out[dst : dst + stride] = pixels[y * stride : (y + 1) * stride]
    return size, size, out


def flatten(width: int, height: int, pixels: bytearray, rgb: tuple[int, int, int]):
    """Composite onto a solid colour, leaving every pixel opaque."""
    out = bytearray(pixels)
    for i in range(0, len(out), 4):
        alpha = out[i + 3]
        for c in range(3):
            out[i + c] = (out[i + c] * alpha + rgb[c] * (255 - alpha) + 127) // 255
        out[i + 3] = 255
    return width, height, out


def encode_rgba(width: int, height: int, pixels: bytearray) -> bytes:
    stride = width * 4
    raw = bytearray()
    for y in range(height):
        raw.append(0)  # filter: none — pngquant/optipng refilter afterwards
        raw += pixels[y * stride : (y + 1) * stride]

    def chunk(ctype: bytes, body: bytes) -> bytes:
        return (
            struct.pack(">I", len(body))
            + ctype
            + body
            + struct.pack(">I", zlib.crc32(ctype + body) & 0xFFFFFFFF)
        )

    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(bytes(raw), 9))
        + chunk(b"IEND", b"")
    )


def compress(path: Path, quantise: bool) -> None:
    """Shrink in place. Quantising drops to a palette — fine for flat gold art,
    but the icons stay RGBA so their anti-aliased edges survive scaling."""
    if quantise and shutil.which("pngquant"):
        subprocess.run(
            ["pngquant", "--quality=80-98", "--force", "--skip-if-larger",
             "--output", str(path), str(path)],
            check=False,
        )
    if shutil.which("optipng"):
        subprocess.run(["optipng", "-quiet", "-o2", str(path)], check=False)


def write(name: str, width: int, height: int, pixels: bytearray, quantise: bool) -> None:
    target = LOGO_DIR / name
    target.write_bytes(encode_rgba(width, height, pixels))
    compress(target, quantise)
    print(f"  {name:<28} {width}x{height}  {target.stat().st_size:>9,} B")


if __name__ == "__main__":
    src = SOURCE.read_bytes()
    w, h, px = decode_rgba(src)
    print(f"master {SOURCE.name} {w}x{h} ({len(src):,} B)")

    # Full-res web copy: same pixels as the master, just quantised.
    web = LOGO_DIR / "q3_logo-web.png"
    shutil.copyfile(SOURCE, web)
    compress(web, quantise=True)
    print(f"  {web.name:<28} {w}x{h}  {web.stat().st_size:>9,} B")

    # Screen-sized mark, sized by height (see SM_HEIGHT).
    sm_w = max(1, round(w * SM_HEIGHT / h))
    write("q3_logo-sm.png", *downscale(w, h, px, sm_w), quantise=True)

    # Square icons: trim the transparent margin, fit and centre, then back the
    # home-screen ones with black. Transparent and black variants of the same
    # size share one downscale — it is the slow step.
    cw, ch, cpx = crop(w, h, px, alpha_bbox(w, h, px))
    fitted = {}
    for name, (size, coverage, background) in ICONS.items():
        if (size, coverage) not in fitted:
            scale = (coverage * size) / max(cw, ch)
            fitted[size, coverage] = downscale(cw, ch, cpx, max(1, round(cw * scale)))
        tile = centre_on_square(*fitted[size, coverage], size)
        if background is not None:
            tile = flatten(*tile, background)
        write(name, *tile, quantise=False)
