# Screen recording studio

> **Quantum Life port (2026-09-18).** Ported from SolarXFactor, admin-only. Videos are not
> embedded in training lessons here, so the lesson-inheritance and attach-to-lesson parts below
> do not apply; a recording's "used in" list shows the presentations that play it, and it
> cannot be deleted while any exist. Trim, combine and upload *do* use ffmpeg/ffprobe and a
> queue worker (the sentence below predates them).

How admins record how-to videos in the browser, where those videos live, and
how they reach members.

Everything is captured client-side. There is no ffmpeg, no queue worker and no
transcoding step — the browser composites the screen and webcam onto a canvas
and hands us a finished video file.

---

## The flow

1. **Studio** (`/admin/screen-recordings/studio`) — the admin shares a screen,
   optionally turns on the webcam and picks which corner it sits in, then hits
   record. The webcam is drawn *into* the frame every tick, so the stored file
   is one self-contained video rather than a video plus an overlay track.
2. **Upload while recording** — `MediaRecorder` emits the file in 3-second
   slices. Those are buffered into fixed-size chunks and POSTed in order to
   `/admin/screen-recordings/{uuid}/chunk`, which appends each one to a single
   part-file under `storage/app/recording-uploads/`. A long walkthrough is
   therefore almost fully uploaded by the time the presenter stops.
3. **Finalize** — the part-file is streamed to object storage in one pass, the
   catalogue row is marked `ready`, and the part-file is unlinked. A poster
   frame grabbed off the canvas is uploaded alongside it.
4. **Publish and use** — the admin sets who may watch, then either shares the
   watch link or drops the recording into a training lesson as a video block.

## Uploading a video recorded elsewhere

**Video Library → Upload a video.** Drag in an MP4 (or MOV, WebM, M4V) recorded
on Zoom, a phone, a camera — anything. It goes up through the same chunked
pipeline as a studio capture, for the same reason: PHP will not take a 400 MB
body.

Once it lands it is an ordinary library recording. Trim it, combine it with
others, schedule it as a presentation, attach it to a training lesson.

**The server measures it, not the browser.** An upload is whatever bytes the
browser sent, and a browser reports nothing useful about a codec it cannot
decode — so on finalize `MediaInspector` runs ffprobe over the assembled file
and its answers win for length, dimensions and content type. The browser's
guesses are sent only as a hint for the progress display.

That same pass is the gate: a file with no video stream is **refused with a
422**, the part-file is deleted and the half-made row removed, so nothing that
is not playable can reach the library and be scheduled in front of an audience.
A poster frame is pulled from the file at the same time, so an upload looks like
everything else in the library rather than a grey box.

Needs ffmpeg/ffprobe. Without them the upload page says so rather than accepting
files it cannot check.

## Storage

Recordings go to the DigitalOcean Space, not the droplet.

On the droplet this is already wired: the `spaces` disk falls back to the same
`AWS_*` values the default `s3` disk uses, so the existing helper configures
both at once and there is only ever one credential pair for one bucket.

```bash
sudo sxf-configure-spaces <ACCESS_KEY> <SECRET_KEY>
```

Both come from the DO console under **API → Spaces Keys** — an access key *pair*
(a ~20-character key id and a ~43-character secret), not a DO API token. The
helper proves a real bucket round-trip before it changes `FILESYSTEM_DISK`,
because the `s3` disk is declared with `'throw' => false` and would otherwise
swallow failed writes silently.

The `SPACES_*` variables exist only to point recordings at a *different* bucket
than the default disk; leave them empty otherwise. With neither `AWS_BUCKET` nor
`SPACES_BUCKET` set — a dev box — the studio falls back to the local `public`
disk and still works end to end.

Objects are written **private**. Nothing ever links to the object URL directly:
playback goes through `/recordings/{uuid}/stream`, which checks who is asking
and only then redirects to a signed URL that expires after
`RECORDINGS_LINK_TTL` minutes (default 3 hours). A leaked CDN path cannot
become a permanent public link.

No bucket CORS configuration is needed — the browser only ever talks to the
application, never to the Space.

Each row records the disk it was written to, so changing `RECORDINGS_DISK`
later affects new recordings only and cannot orphan what is already stored.

## Server requirements

The chunk endpoint is the one thing that needs the server's cooperation.

| Setting | Needs to be | Why |
| --- | --- | --- |
| PHP `upload_max_filesize` | ≥ 8M recommended | caps the chunk size |
| PHP `post_max_size` | ≥ 8M recommended | same |
| nginx `client_max_body_size` | ≥ chunk size + envelope (`8m`) | nginx rejects the chunk before PHP sees it |

The studio never sends a chunk larger than the server will accept:
`RecordingStorage::chunkBytes()` clamps the configured size down to 80% of the
smaller of the two PHP limits, floor 256 KB. Small limits mean more requests,
not failures — but **nginx is the exception**: it will 413 a chunk larger than
`client_max_body_size`, and it does not tell PHP. Set it explicitly.

## Housekeeping

A studio tab closed mid-capture leaves a part-file on the droplet's small disk
and an `uploading` row nobody can act on. `recordings:prune` sweeps both after
`RECORDINGS_STALE_UPLOAD_HOURS` (default 24) and is scheduled nightly at 03:30.

```
php artisan recordings:prune          # use the configured window
php artisan recordings:prune --hours=2
```

Before it prunes, the bytes are still recoverable: the recording's manage page
offers **Store what arrived**, which runs the finalize step by hand. The same
button appears as **Retry storing** when the upload to the Space failed — a
capture cannot be made twice, so the part-file is deliberately kept when
storage rejects it.

## Who can watch

Set per recording on its manage page:

| Setting | Who gets in |
| --- | --- |
| Admins only | admins, plus whoever recorded it |
| All signed-in members | any authenticated member |
| Members at a role level | members whose role level ≥ the chosen role |
| Anyone with the link | everyone, signed in or not |

Two rules sit above that list:

- **Drafts are invisible.** Nothing unpublished is reachable except by an admin
  or its author, whatever the visibility says.
- **A lesson's gate wins.** A recording embedded in a training lesson is
  playable by anyone who can open that lesson, published or not. Attaching it
  *is* the decision to show it, so the library setting does not have to be kept
  in sync by hand.

## Trimming

Each recording's manage page has a **Trim** panel: scrub the player to a point,
hit *Use playhead* for the start or the end, and **Trim**. The cut runs on the
queue, and the recording keeps playing at its current length until the new
version swaps in.

Two decisions worth knowing:

- **It re-encodes, it does not stream-copy.** Stream copy is instant but can
  only cut on a keyframe, so "remove the first ten seconds" lands wherever the
  nearest keyframe happens to be — often mid-word. The re-encode is exact. It
  costs CPU, which is why it is a background job and runs at `nice 10` so a long
  encode does not compete with PHP-FPM on a two-core droplet. Output is H.264/AAC
  MP4, which also fixes the missing-duration problem WebM captures have.
- **It is non-destructive.** The first trim moves the untouched capture to
  `original_path` and keeps it forever. Every later trim re-cuts *that*, so a
  narrow cut can be widened back out, quality never compounds, and **Revert**
  restores the original exactly. The cost is holding two objects per trimmed
  recording; a capture cannot be made a second time, so that is the right trade.

Requires ffmpeg on the host:

```bash
sudo apt-get install -y ffmpeg
```

Without it the Trim button reports that ffmpeg is missing rather than failing
silently. Paths and encode settings are in `config/screen-recordings.php` under
`trim` (`FFMPEG_PATH`, `RECORDINGS_TRIM_PRESET`, `RECORDINGS_TRIM_CRF`,
`RECORDINGS_TRIM_NICE`, `RECORDINGS_TRIM_TIMEOUT`).

Trimming needs a **running queue worker** — it is a queued job, so on a host
where nothing is consuming the queue the recording will sit at "Trimming…"
forever.

## Combining recordings

**Video Library → Combine videos.** Click recordings to add them, arrange them
with the arrows, name the result, press Combine. The output is a brand new
recording; the ones that went into it are untouched and stay in the library.

It runs on the queue and takes roughly a minute per minute of finished video.

### Why it is two ffmpeg passes

Clips never match. A screen capture is 1920×1080 at 30fps with system audio; a
webcam announcement might be 1280×720 and completely silent. ffmpeg cannot
concatenate streams that disagree about resolution, frame rate, pixel format or
channel layout — and a silent clip has no audio stream at all, which breaks a
naive concat outright.

So `VideoComposer` does:

1. **Normalise each clip** to one shared canvas — the widest and tallest among
   them, capped at 1080p — letterboxed rather than cropped, at 30fps, with
   silence synthesised for clips that have none. One re-encode per clip; this is
   where the time goes.
2. **Concatenate** with `-c copy`, which is instant because by then every input
   is byte-compatible.

A single giant `filter_complex` would also work, but it fails as one unit: one
awkward clip kills the whole export with an ffmpeg error nobody can read.
Per-clip passes fail per clip and name the one that broke.

### Behaviour worth knowing

- **Sources are copied, not consumed.** Deleting or trimming a source later does
  not change an already-built video. **Rebuild** re-renders from the clips as
  they are now.
- **A clip whose source was deleted blocks a rebuild** rather than quietly
  producing a shorter video. The clip row survives (with its original title) so
  the sequence still reads correctly.
- **The same recording can appear more than once** — an intro bumper at both
  ends is a legitimate thing to want.
- **A failed rebuild leaves the previous version playable.** Only a composition
  that has never rendered drops to `failed`.
- Compositions are ordinary recordings: they publish, take a visibility, go into
  a lesson, and can themselves be trimmed or combined again.
- Capped at `RECORDINGS_COMPOSE_MAX_CLIPS` (12) per build.

## Known limitations

- **Browser support.** `getDisplayMedia` is desktop-only and needs HTTPS.
  Chrome, Edge and Firefox work; mobile browsers cannot capture a screen.
- **Seeking in WebM.** The studio prefers MP4 (`avc1`) where the browser's
  recorder supports it, because `MediaRecorder`'s WebM output carries no
  duration in its header and some players will not scrub it. On a browser
  without MP4 recording the video plays fine but the seek bar may be unreliable.
  Fixing that properly means remuxing server-side, which would put ffmpeg on the
  droplet — deliberately out of scope.
- **Trimming cuts the ends only.** There is no way to remove a section from the
  middle of a recording. Pause/resume during capture is still the tool for
  skipping a mistake mid-recording — or trim the piece and combine around it.
- **Combining is whole clips in sequence.** There is no timeline, no per-clip
  in/out inside the combine screen (trim the source first), no transitions, no
  overlapping audio, and no way to record a new clip directly into a
  composition — record it, then rebuild with it added.
