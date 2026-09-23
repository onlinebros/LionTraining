# Training library — the Kartra migration

The *Releasing The-LION* program, moved off Kartra and into our own storage,
served only to members who have bought it.

Built and verified on `q3.onlinebros.com` (dev) on 2026-09-21.

**The media is in object storage.** All 23.03 GB — 124 videos and 89 worksheets
— is in DigitalOcean Spaces `storage-q3-01` (nyc3), private, and dev serves it
through signed URLs. Verified by listing the bucket and comparing every object
against its local source: 211 objects, all sizes match, nothing orphaned. (211
rather than 213 because two worksheets are shared by two lessons each.)

**The application is not on production yet** — § 6 step 2 is what remains, and
it no longer moves any bytes.

---

## 1. What the material is

Scraped from `besafe.kartra.com/portal/Lion` into `scripts/kartra-*.json`, then
downloaded to the private local disk.

| | |
|---|---|
| Modules | 21 |
| Lessons | 165 |
| Videos | 124 MP4, 24 GB |
| Worksheets | 89 PDF, 26 MB |
| Content blocks built | 370 |

Sources, all in `scripts/`:

| File | Holds |
|---|---|
| `kartra-content.json` | the module → lesson tree, with video and thumbnail URLs |
| `kartra-page-content.json` | each lesson's body text/HTML and its attached files |
| `kartra-videos.json` | 124 video titles and their CloudFront URLs |
| `kartra-structure.json` | an earlier, thinner version of the tree; unused |

**The 24 GB on disk is the only copy.** The Kartra subscription is the thing we
migrated away from. The CloudFront URLs in the scrape were still answering on
2026-09-21 and were used to repair one truncated file, but do not plan on that:
treat the extract as unrecoverable and never let a command delete it without
verifying the replacement first.

### Integrity

All 124 videos were checked byte-for-byte against the source `Content-Length`
on 2026-09-21. 123 matched. One — *Lesson 20: How Do We Learn To SEE?* — was
35% of its true length after a download was interrupted, and was re-fetched and
re-verified (371,708,165 bytes, tail decodes clean).

Worth knowing: **`ffprobe` reported the full 45-minute duration for the
truncated file.** An MP4's `moov` atom describes the whole recording and sits at
the front, so it survives truncation intact and duration alone proves nothing.
Compare sizes.

Two worksheets — *What is Life all about* and *More Evidence* — were attached to
two lessons each under separate Kartra download ids. They are byte-identical,
and both content blocks point at one file. That is correct, not a clash.

## 2. Rebuilding the library from scratch

In order. Each step is safe to re-run.

```bash
cd backend

php artisan migrate --force
php artisan kartra:import  --json=../scripts/kartra-content.json --no-download
php artisan kartra:content --json=../scripts/kartra-page-content.json --no-download
php artisan training:adopt-media          # attach the files already on disk
php artisan kartra:seed-training --fresh  # build categories, lessons, blocks
```

`training:adopt-media` exists because the downloader named every file
`<slug-of-title>-<row id>.<ext>`, and that row id is a database autoincrement.
Rebuild the import table and the ids in the filenames mean nothing. Matching
therefore ignores the trailing id and keys on the slug, which was verified
unique across all 124 videos and all 89 worksheets. Use `--dry-run` first.

**`kartra:seed-training --fresh` no longer destroys the import.** It used to
call `truncate()`, and on Postgres that is `TRUNCATE ... RESTART IDENTITY
CASCADE` — CASCADE follows every foreign key pointing at the table, so clearing
the training tables silently emptied `kartra_imports` and `video_assets` too.
The seeder then reported "no kartra modules found" as though the import had
never run. It uses ordered deletes now, which do not cascade.

## 3. How access works

Four gates, outermost first. A request has to pass all of them.

| Gate | Where | Refuses with |
|---|---|---|
| Library released? | `training.visible` middleware | **404** |
| Signed in | `auth` | redirect to login |
| Membership live | `subscribed` | redirect to billing |
| Not on commission hold | `training.unlocked` | redirect to billing |
| This module released to *you* | `TrainingMediaController` / the models | 404 |

### The visibility switch

`Admin → Settings → Training Library`, stored as the site setting
`training_visibility`, falling back to `config/training.php` (which ships
`admin`).

While it is `admin`, every training route answers **404** for anyone but an
administrator, no video or worksheet byte is served, and the Training link is
gone from the member sidebar. Administrators browse the library exactly as it
will ship, ahead of every drip date. That is what makes it safe to load the
material onto production before it is ready.

404 rather than 403 is deliberate: a 403 confirms there is something there.

### Media is never a static URL

`/member/training/video/{block}`, `/poster/{block}` and `/download/{block}` all
re-run the full check on **every request, including every seek**. A copied link
stops working when the membership does.

On the local disk the application streams the file itself and handles HTTP
range requests, so scrubbing works without giving out a durable URL. On object
storage it mints a signed URL with a TTL (`training.link_ttl_minutes`, 180 by
default) after the access check has passed.

`TrainingStorage::temporaryUrl()` deliberately returns null for local disks. A
local "temporary URL" is a `/storage/...` link carrying a signature and no
identity, which would outlive the membership that paid for it.

For the same reason `'serve' => false` on the local disk in
`config/filesystems.php`. It registered `GET`/`PUT /storage/{path}` over the
private disk on a signature alone. Nothing used it; it is off.

### Responses are never cached

Cloudflare is in front of the app and will cache a 200 with a video content
type. A cached lesson video is one served without any access check to anyone
holding the URL. Every media response carries `Cache-Control: private,
no-store` and `X-Accel-Buffering: no`.

## 4. The release schedule

Counted from the start of the **paid** membership, not from signup — see
`User::trainingClockStartedAt()`. The anchor is, in order of preference:
`billing_trigger_met_at` (a commission-hold partner's billing started when
their commissions cleared), `trial_ends_at` (a parked pre-launch trial converts
at launch, and that is month one), `current_period_start`, then the hand-set
`active_start_date`.

The distinction matters here: a partner can sit on a parked trial or on
commission hold for months. Counting from signup would hand someone who has
never paid a library they have not bought. A member with no clock at all sees
only the modules with no delay.

Laid down by the seeder, **editable per category and per lesson in the admin
afterwards** — changing one does not need a re-seed.

| Module | Opens |
|---|---|
| Finding The Ancient-Path | month 1 |
| Unlocking The LIONS Cage | month 2 |
| The-Eyes of The-HEART | month 3 |
| Learn To SEE | month 4 |
| Building A LION'S Life | month 5 |
| The-HEART of The Matter | month 6 |
| Your BIRTH-RIGHT | month 7 |
| HEARTS That SEE | month 8 |
| Living From The-HEART | month 9 |
| Healing From The-HEART | month 10 |
| Farther-Back and Higher-Up | month 11 |
| Webinar replays, Media Center, Inspiration, Movies, Nature, Peak Performance, Science | open from day one |

A locked module is shown, not hidden: the card carries the date it opens. The
reference sections do not consume a month — `config/training.drip.always_open`
lists them by title.

A lapsed subscription stops access at the `subscribed` gate, so the whole
library closes until it resumes.

## 5. Storage

`config/training.php` picks the disk: the DigitalOcean Space when its
credentials are present, otherwise the private local disk, which is where the
extract already sits. Same pattern as `config/screen-recordings.php`.

The disk is written onto each row when the bytes are placed
(`video_assets.disk` / `.storage_path`, `training_content_blocks.file_disk`), so
a half-finished transfer cannot point unmoved videos at a bucket that does not
hold them. A null value means "wherever the config points".

**Nothing is ever written to the `public` disk.** The video is the product.

### Permissions

The Kartra download left `storage/app/private/kartra-videos` at `drwx------`
with `mask::---`, which nullified the ACL entries — so `www-data` could not read
a single video and every stream 404'd while the file was plainly there. Fixed
on dev 2026-09-21:

```bash
setfacl -m m::rwx -m u:www-data:rwx storage/app/private/kartra-videos
setfacl -d -m u:www-data:rwx -d -m u:ai:rwx -d -m m::rwx storage/app/private/kartra-videos
setfacl -R -m m::rX -m u:www-data:rX storage/app/private/kartra-videos
```

Check with `sudo -u www-data test -r <a video>` — not with `ls`, which shows
ACL entries that the mask has already cancelled.

## 6. Putting it on production

**The bytes go up once, from wherever they already are. The droplet never holds
the library at all.**

That is the whole shape of it. The obvious route — rsync 24 GB to the droplet,
then upload to the Space from there — moves the same bytes twice and leaves a
permanent 24 GB on an 80 GB disk. Instead the dev box, which already has the
extract, uploads straight to the Space, and production attaches its rows to
those objects by name.

### Prerequisite: the S3 adapter

`league/flysystem-aws-s3-v3` was **not installed** — the `spaces` disk had been
configured in `config/filesystems.php` since the screen-recorder work but could
never have worked. Added 2026-09-21. Any environment using the Space needs it,
so `composer install --no-dev` on the droplet must run after this ships.

### Credentials

In `.env` (dev) and `shared/.env` (production):

```
SPACES_KEY=…
SPACES_SECRET=…
SPACES_REGION=nyc3
SPACES_BUCKET=storage-q3-01
SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
RECORDINGS_DISK=public
```

`RECORDINGS_DISK` is **not optional**. `config/screen-recordings.php` reads
`'spaces'` the moment any bucket is configured, so without the pin, setting up
the training library silently redirects new screen recordings into the Space as
well. Set it deliberately when that is what you want.

The endpoint is the **regional** one. Laravel builds the virtual-host URL
(`storage-q3-01.nyc3.…`) itself from bucket + region; giving it the
bucket-specific URL produces `storage-q3-01.storage-q3-01.nyc3.…`.

The key in use on 2026-09-21 is **account-wide** — it also reaches
`solarxfactor-storage-01`. It works, but a key scoped to `storage-q3-01` alone
would be better, since this one sits in the production `.env`.

### Step 1 — upload, once, from wherever the extract lives

```bash
php artisan training:publish-media --dry-run
php artisan training:publish-media --files --limit=3   # prove the credentials cheaply
php artisan training:publish-media                     # the 24 GB; run under tmux
php artisan training:publish-media --verify            # re-check every object
```

Resumable by design: each object is copied, verified by size, and only then
recorded on its row. A second run skips what landed; an interrupted run loses
at most the file in flight. Size comparison rather than checksums — a truncated
upload always changes the length, and hashing 24 GB twice would add hours.

Objects are written **private**. Confirm with an anonymous `curl`; it must 403.

### Step 2 — build the library on production

```bash
cd /var/www/app.q3.life/current/backend
composer install --no-dev          # brings in the S3 adapter
php artisan migrate --force
php artisan kartra:import  --json=../../scripts/kartra-content.json --no-download
php artisan kartra:content --json=../../scripts/kartra-page-content.json --no-download
php artisan training:adopt-media --disk=spaces     # attach to the published objects
php artisan kartra:seed-training --fresh
```

`--disk=spaces` is the difference. It indexes the bucket instead of the local
download and writes `disk`/`storage_path` straight onto each row, so production
needs no copy of the media. It **fails rather than succeeding emptily** if the
prefix is wrong — a library of lessons that play nothing is worse than a failed
command.

`TRAINING_VISIBILITY` ships `admin`, so nothing is member-reachable at any point
during this.

### Step 3 — check it, then open it

As an admin on production: play a video from a late module, scrub it, download a
worksheet. Then `Admin → Settings → Training Library → Live for members`.

### Dev and production share one bucket — know what that means

"Upload once" means both environments read the same objects under
`training/videos` and `training/files`. That is deliberate: the content is
identical, and it is what keeps the 24 GB off the droplet. But it has a
consequence worth stating plainly.

**The dev box now holds write credentials to production's media store.** A
destructive command run on dev — `training:publish-media --prune` above all —
reaches the objects production is serving. There is no separate copy to fall
back on.

If that ever becomes uncomfortable, the fix is to give each environment its own
prefix (`TRAINING_VIDEO_PATH`, `TRAINING_FILE_PATH`) and pay for a second copy,
or to issue dev a read-only key. Neither is needed while one person is running
both, but do not let it be a surprise later.

### Afterwards

The dev box still holds the only local copy of the extract, and it is the only
thing that is not in the Space. Keep it.

Do not run `training:publish-media --prune`. It deletes the local originals,
and with dev and production sharing one bucket there would then be exactly one
copy of the library in existence, with no backup. Set up bucket versioning or a
separate backup before that is even worth discussing.

### Still to decide

- **Poster frames.** `video_assets.thumbnail_path` is empty for all 124; the
  poster route works but has nothing to serve, so players show a black first
  frame. One `ffmpeg` pass over the extract would fix it.
- **Durations.** Also empty, so the lesson page shows no runtime. Same pass.
- Kartra's own thumbnail URLs are in the scrape but they are all the same lion
  logo, so they are not worth importing.

## 7. Credentials

The Kartra portal login was hardcoded as the default of `--email` and
`--password` on `kartra:import` and `kartra:content`, which put a working login
in the repository and printed the email into every console log. It now comes
from `KARTRA_PORTAL_URL` / `KARTRA_EMAIL` / `KARTRA_PASSWORD` via
`config/services.kartra`. Those are unset in normal operation — the material
lives in our own storage now.

**The password that was in the repo should be rotated at Kartra**, and is in the
git history regardless.
