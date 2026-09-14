# PlasmaGuard product imagery — provenance

Third-party product photography, mirrored here rather than hot-linked so the
storefront does not break when the vendor reorganises their media library, and
so the page is not making requests to a domain we do not control.

**These are PlasmaGuard's images, not ours.** Manufacturer photography used by
an authorised referral partner is ordinary practice, but written permission has
not been obtained — it is item 9 on the meeting agenda in
`memory-bank/vendor-referral-framework.md`. If they decline, delete this
directory and blank `images` in the product's `config/vendors.php` entry; the
page renders without them.

| File | Source | Retrieved |
|---|---|---|
| `pg-pro-generator.webp` | https://plasmaguard.com/wp-content/uploads/2023/09/pg-pro-generator.webp | 2026-09-08 |
| `pg-pro-sensor_sq.webp` | https://plasmaguard.com/wp-content/uploads/2023/09/pg-pro-sensor_sq.webp | 2026-09-08 |
| `pg-pro-hub-sq.webp` | https://plasmaguard.com/wp-content/uploads/2023/09/pg-pro-hub-sq.webp | 2026-09-08 |

All three: 1080 × 1080, WebP with an alpha channel, unmodified. The transparency
is why these three were chosen over the rest of the library — they sit directly
on the Q3 black with no white plate around them.

## Derivatives

The 1080px files are the masters and are **not served**. The page uses
downscaled copies sized to what it actually displays, at 2× for retina:

| Suffix | Used for | Displayed at |
|---|---|---|
| `-560` | hero product shot | 280px tall (desktop), 168px (phone) |
| `-320` | "What arrives" gallery | ~124px tall (desktop), 68px (phone) |

Serving the masters meant shipping a 1080px square to fill a 156px frame. The
four images on the page came to 79 KB; they now come to 42 KB.

Regenerate with ffmpeg — `yuva420p` is what keeps the alpha, and `lanczos` is
what keeps the edges clean at this reduction:

```sh
for f in pg-pro-generator pg-pro-sensor_sq pg-pro-hub-sq; do
  for w in 560 320; do
    ffmpeg -y -i $f.webp -vf "scale=$w:-1:flags=lanczos" \
      -c:v libwebp -pix_fmt yuva420p -q:v 82 $f-$w.webp
  done
done
```

Only the scale changes — no crop, no recolour, no filter.

Two further images were downloaded and then discarded rather than kept unused:
`pg-app-com.jpg` (mobile app in a hand) and `pg-pro-com-sq.jpg` (full system in
an office). Both are bright, full-bleed photographs that fight the dark theme.
If they are ever wanted, the URLs follow the same pattern as above.
