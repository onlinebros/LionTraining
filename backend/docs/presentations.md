# Presentations

> **Quantum Life port (2026-09-18).** Ported from SolarXFactor. Differences here:
> - **Admin-only.** `PRESENTATIONS_OPEN_TO_MEMBERS=false`: the `/member/presentations` and
>   `/member/funnels` pages (Your Rooms, prospects, reports) answer 403 to non-admins and are hidden
>   from the partner sidebar. Admins reach them from *Presentations → Your Rooms*. Guest pages
>   (`/watch`, `/flow`) are public, as they must be.
> - **Calls to action:** `join` goes to `/join/{code}`. There is no homeowner funnel, so the
>   `customer` kind was removed and `schedule_call` needs a scheduler URL (hidden without one).
> - **No invite-lead table:** a CTA click only stamps `cta_clicked_at`; the host moves a prospect
>   into the CRM from the prospects report. `invite_lead_id` was dropped.
> - **No notification inbox:** `App\Services\NotificationService` only logs. Arrivals, questions
>   and choices show up live in Your Rooms.
> - **No neutral host**, and videos are not embedded in training lessons. Sections below about
>   either describe SolarXFactor.

A recording from the video library, put in front of prospects, with the member
who invited them answering questions in chat while it plays.

There are **two formats** of showing, and a **funnel** that strings showings
together. The formats differ in one respect only — whether there is a shared
clock:

| | Scheduled | Always open |
| --- | --- | --- |
| `format` | `scheduled` | `on_demand` |
| When it plays | at a set time, for everyone at once | from the start, whenever somebody opens the link |
| Position | `server_now − started_at`, the same for every viewer | each viewer's own, resumed between sittings |
| Status | `scheduled → live → ended`, by `presentations:run` | `live` from creation, never ends |
| Pausing / rewinding | refused — it is one shared moment | allowed; skipping *ahead* is not |
| Ends | when the video runs out | never |

Everything else is shared: the same invite codes, the same isolated
conversations, the same console, the same conversion tracking and reports. An
always-open share is a presentation with the clock taken out, not a parallel
feature.

Where this document says "the room's position" or "showtime", it is describing
a scheduled showing; the "Always open" section below says where an on-demand
share behaves differently.

A **funnel** is a set of always-open showings with choices placed on them — see
"Calls to action" and "Funnels" below.

## Release gate

`PRESENTATIONS_OPEN_TO_MEMBERS=false` (the default) makes the whole thing
admin-only:

- the Presentations item is hidden from the member sidebar;
- `/member/presentations/*` returns 403 to anyone who is not an admin;
- admins get the **full member experience**, so the flow can be rehearsed end to
  end before anybody else has it.

Guest pages are deliberately **not** gated. A test guest is an ordinary person
with a link and no account, and gating them would make the thing untestable. A
showing is only reachable by someone who was given its URL.

Flip the flag and `php artisan config:cache` to release it.

## Times are Eastern

Everything is **booked in Eastern** — an admin types 7:00 PM and 7:00 PM is what
the company means. Timestamps are still stored in UTC; the timezone only decides
how they are read in and written out.

`PRESENTATIONS_TIMEZONE` is `America/New_York`, deliberately **not** a fixed
"EST". Literal EST is UTC-5 all year, but the east coast is on EDT (UTC-4) from
March to November — a fixed offset would put every summer showing an hour away
from what a clock in New York actually reads. The zone handles the switch and
the label follows it: **EST** in winter, **EDT** in summer.

Every page that names a time renders it through
`partials/presentation-time.blade.php`, which prints the Eastern time on the
server and lets the browser append the viewer's own time beside it — but only
when the two differ, so nobody in Florida is told "7:00 PM ET · 7:00 PM your
time". The scheduling form echoes the same thing back live, so an admin working
from Phoenix sees immediately that their 7:00 PM is 4:00 PM where they sit.

CSV exports use Eastern too, with the column labelled so.

## How the presentation is described to guests

**Decided by the owner, not by engineering. Do not "fix" this back.**

The registration page does **not** tell guests the presentation is a recording.
The line that used to say so was removed on 2026-09-01 at the owner's direction.

What remains on that page is the data notice only — that the inviting member can
see they attended, and what their email will be used for. Those are about
personal data, not about the format, and they stay.

The one thing worth keeping an eye on is the difference between *not saying*
something and *claiming the opposite*. Omitting the format is an ordinary
product choice. Actively asserting the event is live is a different thing, and
that is where the exposure sits for an income-opportunity business:

- the **"LIVE NOW" pill** in the room, and **"Happening now"** on the
  registration page, both assert liveness;
- a recorded presenter referring to the audience, the date, or anything only a
  live speaker could know;
- countdown or scarcity offers attached to an event that runs every week.

Neutral wording that stays true either way: "In progress", "On now", "Started".

## What the call to action asks for

The same machinery recruits members and wins customers, so the button at the end
is configuration, not a hard-wired link. Set per showing (and per repeating
schedule, which its occurrences inherit):

| Type | Where it goes |
| --- | --- |
| `join` | `/invite/{code}` — become a member through this sponsor |
| `customer` | `/go/{code}` — the homeowner funnel, credited to the same member |
| `custom` | any URL you give it |
| `none` | no button |

Whichever it is, the link carries the **inviting member's own code**, so credit
lands in the same place regardless of path. Headline, button text and small
print can be overridden; blank uses sensible wording for the type.

`PRESENTATIONS_DEFAULT_CTA` is the phase the business is in — `join` while
recruiting. Changing it changes what new showings default to, not what existing
ones do.

## Conversions, and the email that changes

**People watch under a throwaway address and sign up under their real one.**
That is the normal case, not an edge case, so matching on email alone would miss
most of the conversions that matter and would credit the ones it did find to
whoever happened to share an address.

So the primary link is a **token**, minted when a guest clicks the call to
action and carried through to the signup form. It never looks at the address, so
it survives any change of one. `ConversionTracker::attribute()` runs inside the
signup flow and stamps `converted_user_id`, `converted_at` and
`conversion_match = 'token'`.

It also credits **every other showing that person watched** under the same
watching address — otherwise a member sees one conversion and a pile of
unconverted rows for the same human.

`presentations:match-conversions` (hourly) is the fallback: guests whose watching
address matches an account, recorded as `conversion_match = 'email'`. It **only
fills gaps** and never overrides a token match, which is the stronger claim.
There is a test for exactly that — an impostor later registering with the
throwaway address must not steal the attribution.

## Prospects report

**Member: Presentations → Prospects. Admin: Presentations → Prospects.**

One row per *person*, not per registration: somebody who came to three calls is
one prospect who came three times, and that is what decides what you say to them
next. Each row carries what they have already watched, how long for, whether
they clicked through, and whether they signed up — including a visible tag when
they **signed up under a different address**, so the member knows the two
records are the same human.

The conversion rate is measured against people who **turned up**, not people who
registered. A show-up rate and a close rate are different questions and blending
them flatters nobody.

Admins see every prospect with who invited them, and can filter by member.

## CRM

`ProspectToCrm` is a deliberately thin, one-directional bridge, not a sync. A
presentation is one evening in a relationship the CRM owns over months, so it
creates or updates the contact, hands over what the presentation knows, and
stops. Nothing reads back.

- Matched on **owner plus email** — the same address under two members is two
  relationships, exactly as in the prospect report.
- `lead_source` is `event`, the CRM's own vocabulary. The finer detail lives in
  `custom_data.presentation` (registrations, attendance, watch seconds, what
  they watched, conversion and how it was matched) where it can grow without a
  migration to someone else's table.
- Filed by the member who owns the relationship. Admins see the report but do
  not push into a member's CRM.

The day the CRM grows properly, this is the only file that has to learn about it.

## Every guest needs an invite code

`/watch/{slug}/{referral_code}` is the only way in. A bare `/watch/{slug}`, or an
unknown code, gets an "invitation needed" page and a 404 — **not** a registration
form.

This is deliberate and worth not undoing. A guest with no inviting member is an
orphan: nothing records who they belong to, and working it out afterwards is a
commission argument that cannot be settled. Head office is not an exception —
the top position has a referral code, and sponsoring direct to the company means
using it.

Two doors, both locked: `show()` refuses to render the form, and `register()`
re-checks the code because the form posts it back as a field and a field can be
edited. A returning guest is let in on their attendee cookie, since they were
attributed when they first registered.

## How a showing runs

1. **Schedule.** Admin → Presentations → Schedule one. Pick a finished
   recording and a time. The recording's length is copied at that moment, so
   trimming it later cannot move an already-booked showing's end time; the
   recording is `restrictOnDelete` so it cannot be deleted out from under one.
2. **Members share their link.** `/watch/{slug}/{referral_code}`. Nothing to
   hand out — every member's link exists as soon as the showing does.
3. **Guests register** with name and email. One consent line covers the
   recording and the fact that their inviting member will see they attended.
4. **It starts itself.** `presentations:run` (every minute) flips
   `scheduled → live` and sets `started_at` to the **scheduled** time, not to
   "now" — every viewer's position derives from it, so a late timer would
   otherwise shift the whole audience and end the showing late.
5. **It ends itself** when the video runs out.

## Always open

A member picks **Always open** instead of a date, and gets a link that starts
the video from the beginning for whoever opens it. Nothing is scheduled and
nothing is waited for.

What changes:

- **Created live.** `status = live` and `started_at = now()` at creation, so
  there is nothing for `presentations:run` to start. `isOverrun()` is false, so
  it is never ended either — it stays open until the member cancels it.
- **No room clock.** `currentOffset()` and `secondsUntilStart()` return `null`.
  The console shows "Always open" and how far each viewer has got, instead of a
  shared "now playing" position.
- **Each viewer has their own position.** `position_seconds` is where they are;
  `furthest_seconds` is the furthest they have reached, and only ever moves
  forward. Coming back later resumes from `position_seconds` rather than
  starting over — a share watched in two sittings is one viewing.
- **Native controls, with a ceiling.** Rewinding is fine; it is their video, at
  their pace. Skipping past `furthest_seconds + 2` is not, because the order the
  case is made in is the whole point. The seek handler clamps it.
- **It still starts on its own.** No button: the page plays as soon as it loads,
  with the same muted fallback a scheduled showing uses.
- **The notification says the right thing.** "Casey started your video", not
  "joined your presentation" — somebody opening a link at eleven at night did
  not join anything.
- **The invite code rule is unchanged.** "Openly shared" still means shared by a
  member, through their code. A link with no code is refused exactly as it is
  for a scheduled showing, because an orphan prospect is a commission argument
  nobody can settle later.

A **series** cannot be always-open: a series is a repeating booking, and
repeating something that is already open means nothing. The column lives on
`presentations` only.

## Calls to action

A **call to action item** is something a guest can be asked to do, written once
in `/admin/cta-items` and placed wherever it is wanted. Four kinds, each
resolving to a page that already exists:

| Kind | Goes to | Default wording |
| --- | --- | --- |
| `join` | `/invite/{code}` — member sign-up | "See how to get started" |
| `customer` | `/go/{code}` — homeowner funnel, the free report | "Get my free report" |
| `schedule_call` | its own URL, or `/go/{code}` if blank | "Book my call" |
| `custom` | its own URL | "Find out more" |

Every destination carries the inviting member's referral code, so whichever
path a prospect takes, the same person is credited. Wording is overridable per
item and again per placement.

### How it opens

`opens_in` decides what clicking does, and both answers are right for different
asks:

| `opens_in` | Behaviour | For |
| --- | --- | --- |
| `new_window` (default) | opens beside the video | something they fill in and come back from — they keep their place, and the member is still there in the chat |
| `same_tab` | goes to the page | a flow done in steps, where the destination *is* the next thing and there is nothing to come back to |

The default small print — "Opens in a new window, so you keep your place here"
— is only used when that is what actually happens. Promising somebody they keep
their place and then taking the page away from them is worse than saying
nothing, so a `same_tab` button has no note unless one is written.

A **branch always stays in the tab**, whatever this is set to: it is the same
journey continuing, not a departure from it.

A same-tab click is logged with `keepalive` before navigating, so leaving the
page does not cost the record of what they did.

The showing's own inline call to action has the same setting, in
`presentations.cta_opens_in`.

**A note on `schedule_call`.** A PRMI qualification call is booked against a
property, by somebody with a client account — there is no one-click booking a
stranger watching a video can reach. The homeowner funnel *is* the path that
ends in a booked call (address → report → claim → book) and credits the same
member throughout, so that is where this points when no URL is set. Set a URL
to send people to an outside scheduler instead. See `prmi-webhook.md` for what
happens after the call.

The showing's own `cta_*` columns still work and are unchanged; an item is the
reusable version of the same thing.

## Choices on a video

A **cue** places a choice on a video at a moment. Two kinds, deliberately in
one table, because to the person watching they are the same thing — a button
that appeared when it became relevant:

- **`cta`** — offers a call-to-action item. Opens in a new window, so the guest
  keeps their place and their chat, and ends the journey.
- **`branch`** — plays another video in the same funnel. Navigates in place,
  because it is the same journey continuing.

A cue has `starts_at_seconds` and optionally `ends_at_seconds`. The panel rises
once, the first time anything is due; options then fade in individually, so a
video offering three things at three moments builds up rather than dropping a
wall of buttons on somebody. Reveal is driven off the **playhead**, not a wall
clock — on a scheduled showing the two agree, and on an always-open share the
playhead is the only truth.

A cue whose target is gone — a deleted item, a video pulled out of the flow —
is never offered. Better it never appears than that a prospect clicks it at the
one moment they were ready to act.

A video with cues shows only its cues; the showing's own always-visible call to
action is used when there are none.

## Funnels

A flow of videos the prospect steers themselves. Built in `/admin/funnels`.

**Every step is an ordinary always-open presentation.** That is the whole
design: a funnel adds branching and one carried identity, and inherits the room,
the chat, the isolation, the heartbeat and the reporting untouched.

```
/flow/{funnel-slug}/{referral-code}
        │
        ▼
  register  ──►  funnel_participants row  ──►  entry video
                 (host decided ONCE here)
                        │
              choice ───┼──► branch  ──►  next video   (same participant,
                        │                               same host, same thread)
                        └──► cta     ──►  report / sign-up / booked call
```

### The participant

`funnel_participants` is the row that makes it hold together:

- **Attribution is settled on entry and carried.** A prospect who follows three
  branches is three attendee rows; without a participant they are three
  unrelated strangers and nobody can say whose they are. The same rule as
  attendees applies to a second member inviting the same address — two
  relationships, not one shared prospect.
- **The conversation is anchored to the video they entered through.** Moving to
  the next video keeps the thread, because a member halfway through answering
  should not have their reply land in a room the prospect has left.
- `current_presentation_id` is where they are; `outcome` is what they ended up
  asking for.

The invite-code rule is unchanged: a flow link with no code is refused. "Openly
shared" still means shared by a member, through their code.

### Tracking

`funnel_choice_events` keeps every choice, with the video, the wording, and how
far in they were when they made it. The wording is **snapshotted** — a cue can
be reworded tomorrow and the record of what somebody actually chose must not
change under it.

`/admin/funnels/{slug}/prospects` and `/member/funnels/{slug}` show the same two
things, scoped to who is asking: where each person got to and the path they
took, and which options people actually take. A branch nobody picks is a video
not worth making; one everybody picks is the thing to say sooner.

### In the console

Steps of the same flow are gathered into one chip — six videos a prospect might
take three of is one conversation, not six rooms. Each person appears **once**,
on whichever video they are on now, with the step and how far into it they are.
The member is notified when somebody makes a choice ("Casey chose *Show me the
numbers*") rather than on every arrival, because a choice is an opening line and
"they are still watching" is not.

## The sync

*Scheduled showings. An always-open share has no clock to sync against — see
"Always open" above.*

```
position = server_now − started_at
```

The server's clock is the only clock. The page measures its skew against the
server once on load and corrects against that, because a guest's machine can be
minutes wrong.

Drift is re-checked every 15 seconds:

| Drift | Action |
| --- | --- |
| under 1s | nothing — correcting is more noticeable than the drift |
| 1–5s | nudge `playbackRate` to 1.03 / 0.97 and catch up invisibly |
| over 5s | hard seek (usually a backgrounded tab) |

### Starting on its own

The presentation begins by itself; a guest waiting on the countdown does not
press anything. Two things make that work:

- **Pre-roll.** The video file opens `PRESENTATIONS_PRELOAD_SECONDS` (120) before
  the start, so the browser buffers the opening while people wait and playback
  begins rather than spinning. A deliberate, narrow relaxation of "no video
  before the start" — someone who grabs it two minutes early gains nothing.
- **Muted fallback.** Browsers block *audible* autoplay without a gesture, and
  someone sitting on a countdown may not have given one. So it tries with sound,
  and on refusal starts **muted** — which is always allowed — and shows a "Tap
  for sound" bar. The presentation always begins; at worst the sound needs one
  tap, which is a much smaller ask than a button to start.

Going live is handled in place rather than by reloading the page, which would
throw away the buffer the countdown just filled.

### Keeping the playhead

There is no seek bar, but a guest can right-click a video and switch the native
controls on. Three layers stop that mattering:

1. `controlslist="nodownload noplaybackrate noremoteplayback"` and
   `disablepictureinpicture` on the element;
2. the context menu is blocked;
3. and whatever still gets through, the player **snaps back**: seeking away
   returns to the room's position, pausing resumes, and a tampered playback rate
   is reset. Only the drift correction may touch the rate.

Running ahead to the offer, or freezing on it, is the one thing a shared room
cannot allow.

## The video is locked until showtime

*Scheduled showings only. An always-open share has no showtime to withhold it
until, so the file is served for as long as the share exists.*

`/watch/{slug}/video` is its own route rather than the recording's normal stream
because a guest is not a member and the recording ACL is about members and
lessons. It refuses to serve the file unless the showing is running, or has
finished with a replay allowed. Without that, anyone registered could pull the
URL the day before and watch the whole thing early.

## Isolation

> A guest's messages reach the guest, the member who invited them, and admins.
> Nobody else.

- **There is no chat room.** Each guest has a private thread. A room-shaped chat
  cannot be made private afterwards, only replaced, so one was never built.
- **Announcements are one-way** and live in their own table. A guest replying to
  one replies into their own thread.
- **An unattributed guest** — arrived on the plain company link — is admin-only,
  but is adopted by the first member who actually brings them.
- **Attribution:** the first member to register a guest keeps them. A second
  member gets a claim row visible only to admins, and is told nothing, because
  telling them would leak that the guest exists.

Enforcement is `PresentationAttendee::scopeVisibleTo()` and `isVisibleTo()`,
plus `PresentationMessage::scopeVisibleTo()`. Nothing else decides visibility.
`PresentationIsolationTest` and `PresentationChatTest` try to break it by id, by
email, through the JSON panel, through the rendered page and through the reply
endpoint. **If one of those starts failing, the leak is real.**

## Working several calls at once

Showings overlap — two can be running while a third is about to start — and a
member's guests are spread across them. **Presentations → Your rooms**
(`/member/presentations/live`) gathers every room the member currently has
people in, guests grouped and labelled by call, answered from one place.

A room stays open in that console from `PRESENTATIONS_CONSOLE_WINDOW` (30)
minutes before it starts until the same after it ends. A conversation should not
vanish the moment a call finishes, because that is exactly when the follow-up
happens.

**A call that starts while the console is open** appears on its own, with its
whole history. The console tells the server which rooms it already holds
(`known=`), so a room that has just opened comes back with everything said in
it — the cursor alone would skip anything from before it appeared. It is
announced rather than quietly added, because a room growing silently in a list
nobody is looking at is the same as not showing it. `known` is opt-in: a caller
that does not say what it holds gets the plain delta.

**Watch along.** The console can show the video of whichever call is selected,
synced to that room's position, next to a track marking where each guest came
in. It is collapsed by default — most of the time a member is reading and
answering, and a video would just eat the screen. Members get their own video
route, because they have an account and no attendee cookie, so the guest check
would refuse them; the same rules apply otherwise.

**Threads are keyed by the guest alone**, not by guest-plus-showing:
`/member/presentations/thread/{attendee}`. Both consoles use the same endpoints,
so there is one implementation rather than two. That puts more weight on the
visibility scope — it is now the only thing between a member and somebody else's
guest — which is why `PresentationLiveConsoleTest` asks for another member's
guest id directly, on read, reply and mark-read, and expects 404 on all three.

## One place to chat

**Your Rooms is the only conversation surface.** Every route into a
conversation — a notification, a guest row on a showing page, a prospect in a
report, an admin looking at a guest list — links to
`/member/presentations/live?guest={attendee}`.

It used to be three: the cross-room console, a per-showing console, and an
admin modal. The per-showing one had been posting replies to a URL that does
not exist, which nothing caught because the tests called the real endpoint
rather than the one the page built. Two consoles doing the same job means one
of them is the stale copy; the fix was to have one.

- The showing page (`/member/presentations/{slug}`) still lists who is there,
  with each row linking into Your Rooms. It has no reply box.
- The admin presentation page does the same. Admins reach every guest through
  the same console, because `visibleTo()` already gives them everyone.

**A deep link works however old the call is.** The console normally holds rooms
that are running, about to start, or only just finished. `?guest=` additionally
pulls in that guest's room whatever its age, so a notification from last month
still opens — and the parameter is carried on every poll, not just the first
load, or the room would drop out from under an open conversation. The guest is
resolved through `visibleTo()`, so naming somebody else's prospect reaches
nothing.

## The member's console

`/member/presentations/{slug}` is a two-pane console, not a list of buttons that
open dialogs. Guests on the left with presence, unread badges and a preview of
the last message; the conversation on the right; a reply box that is always
there. Enter sends, Shift+Enter breaks a line.

**One poll carries everything** — room state, the whole guest list, and every
message newer than `since` across all of that member's threads. The client holds
the full set, so clicking between prospects costs no request at all. During a
live showing a member moves between people constantly, and a fetch per click is
what made the earlier modal version feel broken.

Reading a thread clears its badge; a member does not have to reply to silence a
question they answered on the phone.

**Notifications deep-link to the conversation.** When a guest joins or asks
something, the alert carries `?guest={id}` and the console opens that thread on
arrival — dropping a member on a list and leaving them to find the person it was
about wastes the one moment that matters.

The page itself renders **no guest data at all** — the list is built from the
feed. That is a stronger position than filtering the template correctly: there is
nothing in the markup to leak.

## What the member sees

Everyone is synced, so "where they are" is not about scrubbing — it is who
arrived when, who is still there, and what is on screen for them now.

- **Now playing** with the current chapter name.
- **Joined at** — the one number that genuinely differs per guest. Someone who
  came in at 12:15 missed the opening and should be caught up, not asked what
  they thought of it.
- **Watched** — accumulated from heartbeat gaps, never trusted from the client.
- **Watching / left**, from a 60-second presence window.
- **Message** opens their private thread; the badge counts unread questions.
- **On a phone** the list and the conversation are two full screens rather than
  a squeezed two-column layout. The conversation fills the viewport with the
  reply box pinned above the keyboard, and the back button carries an "N
  waiting" badge — a member deep in one conversation would otherwise have no
  idea somebody else has been waiting.
- **Export CSV** of their own guests.

## Members scheduling their own

A member can put a released recording on at a time that suits their own team and
get their own link for it. **Presentations → Schedule your own.**

Two things keep it safe:

- **Only released recordings.** `member_schedulable` is off by default and is an
  admin decision per recording (the toggle is on the recording's page). The
  library holds internal training and half-finished captures, and none of that
  should be one click from a prospect. The query is scoped, not merely
  validated by id.
- **A personal showing is private.** `owner_user_id` marks it as one member's.
  It does not appear in anyone else's list and is **404, not 403**, if another
  member guesses the slug — so its existence is not confirmed either. Admins see
  everything.

Company showings — the ones admins schedule — are unchanged: `owner_user_id` is
null, every member gets their own link to the same event.

Members are capped at `PRESENTATIONS_MEMBER_LIMIT` (20) showings still ahead of
them, so one enthusiastic person cannot fill the calendar. `PRESENTATIONS_MEMBERS_CAN_SCHEDULE`
turns the whole thing off; it is separate from `open_to_members`, because a
member can reasonably be allowed to *use* presentations without being allowed to
*create* them.

Members create one-off showings, as many as they like. Repeating schedules stay
an admin-only tool for now.

## Repeating schedules

**Presentations → Repeating schedules.** One recording, a set of days and a set
of times — "Tuesdays and Thursdays at 7:00 PM". Times are Eastern like
everything else.

The series is only a rule. `presentations:generate` (daily at 04:00) materialises
each occurrence as a **real presentation row** with its own link, guest list and
conversations, keeping a rolling window `weeks_ahead` in front. Modelling it as a
repeat flag on a single row would mean every Tuesday's guests piling into the
same inbox.

Behaviour worth knowing:

- **It never backfills.** A showing generated into the past would be started
  immediately by the runner and end before anyone heard about it.
- **A cancelled showing stays cancelled.** The generator checks soft-deleted rows
  too, so it cannot undo an admin who cancelled one occurrence on purpose.
- **Stopping a series leaves its showings alone.** People may already hold links,
  and those guest lists are real. Cancel individual future showings from the
  presentations list.
- Saving the form fills the calendar immediately rather than waiting for the
  nightly run, so an admin can see it worked.

## Chapters

Typed once per **recording**, not per showing, so the same talk scheduled ten
times has them everywhere. Admin → the showing → Chapters, one per line:

```
0:00 Welcome
4:30 The problem
14:02 The Compensation Plan
```

## Known limits

- **Polling, not websockets.** Panel and chat poll every 5s, heartbeats every
  20s. Fine to a few hundred concurrent guests; Laravel Reverb is the upgrade
  path when a showing reliably clears ~300.
- **One quality level.** A guest on weak mobile data will buffer. Generating
  smaller renditions with ffmpeg is the fix, and ffmpeg is already installed.
- **The presenter is a recording** and cannot answer anyone. Everything live
  comes from the chat — which is why the chat is the thing to promote. Note this
  is an engineering limitation, not something the guest pages say out loud; see
  "How the presentation is described to guests" above.
- **A funnel is a graph, and nothing checks it for loops.** Two videos that
  branch to each other are legal and will let somebody go round for ever. The
  admin page shows the whole flow on one screen so this is visible, but it is
  not prevented.
- **An always-open share has no attendance ceiling.** Nothing ends it and
  nothing caps it, so a link shared widely keeps collecting viewers — and every
  one of them lands in the inviting member's console. Cancel the share to close
  it.
- **Live streaming is out of scope.** See `PLAN-live-broadcast.md`; it would be
  a second event type behind the same machinery.
