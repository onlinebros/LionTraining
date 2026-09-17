# Partner Spot Import

How an entire organisation from another company arrives in our genealogy intact,
and how its people take ownership of the positions held for them.

The shape of the deal: a partner company gives us their membership list with its
parent/child structure. We hold every one of those positions in our tree before
anybody signs up. Their members visit a co-branded page, prove who they are with
an ID and code their own company issued, and the position becomes their Quantum 3
Solution account — at the level it has held since the day the list was imported.

---

## 0. No personal data crosses the boundary

**Four columns. Two identifiers, a code, and the shape of the tree.** No names,
no email addresses, no phone numbers, no addresses, no join dates.

An imported position is a place in a structure, and a place in a structure does
not need to know who is coming to stand in it. Each member supplies their own
details, to us, when they claim — having chosen to.

This removes a whole category of problem rather than managing it:

- We cannot leak a list we never received.
- We cannot show an upline the name of somebody who has not joined us.
- We cannot email a stale address its owner never gave us.
- A partner company can hand the file over without a data-processing argument.

Because that only holds if it is enforced rather than requested, it is enforced
in three places:

| | |
|---|---|
| `SpotImportTemplate::COLUMNS` | The four columns, and nothing else. `ImportTemplateTest` fails if a column carrying personal data is added. |
| `SpotImportParser` | Values in unrecognised columns are discarded on read. There is no `raw` payload — a column kept "just in case" is how the data we declined to receive gets stored anyway. |
| `partner_import_rows` | Has no name, email, phone or address column to put it in. |

Column *names* we did not recognise are kept on the batch (`ignored_columns`)
and shown on the review screen, so an admin can tell the partner what they sent
and we threw away. Names only, never values.

The one thing this costs: we cannot tell, at import time, that a position
belongs to somebody who is already a Quantum partner — we have nothing to match
on. That surfaces at claim instead, when they cannot reuse the email address on
their existing account. It is the right way round.

## 1. The constraint that shapes everything

**Placement is permanent.** There is no operation in this system that moves a
position, deliberately — see `memory-bank/decisions.md` and the note on
`users.placement_path`. Everything else about an import can be corrected by
uploading a better file. A leg committed under the wrong partner cannot be, and
by the time anyone notices there are real people sitting in it.

Two things follow, and they account for most of the design:

1. **Nothing reaches the genealogy that an admin has not been shown.** A CSV is
   parsed into a staging table, checked as a whole tree, and displayed with every
   leg's proposed parent before a single `users` row is written.
2. **Anything that would land wrongly stops the whole batch.** One unreachable
   parent fails the import; it does not import 39,999 rows and log the last one.

## 2. Where holding spots live

**In `users`, with `account_status = 'holding'`.** Not in a side table
materialised on claim.

A holding spot is a real row with a real `placement_path` in the same ltree index
as everybody else. That is what lets an unclaimed spot sit *between* two
activated partners without any cross-table path arithmetic, and it makes claiming
a matter of filling in blanks rather than moving a position.

The cost is that `users` now contains rows that are not people, and every screen
that counts partners has to say so. That cost is paid in exactly two places:

| | |
|---|---|
| `User::scopeActivated()` | The default for anything that lists or counts users. |
| `GenealogyService` | Every query defaults to activated-only; `$includeHolding` is opt-in. |

Callers that genuinely want the physical structure — the spots screens, the
importer — say so explicitly. Everything else gets members.

## 3. Compression

An unclaimed spot **occupies a position but not a level**.

Commissions and level counts skip it and land on the nearest ancestor who is a
real member. An unclaimed spot has no owner and no payout account, so an amount
that lands on one is an amount nobody receives; and an activated partner should
not be pushed a level deeper because the person above them has not claimed yet.

This is not only a payout rule — it is what makes the team tree correct. A
partly-claimed leg (imported head has not claimed, three people below them have)
is the normal state of an import in its first month. Filtering unclaimed rows out
of the tree without re-hanging their descendants would orphan those three
partners: they would vanish from the team of the person whose team they are in.
`GenealogyService::compress()` re-parents onto the nearest activated ancestor and
`HoldingSpotVisibilityTest` pins it.

When a spot is claimed, everything below it shifts down one level from that
moment. Levels are computed, never stored, so this needs no backfill.

## 3a. Scale: what 1.3 million rows changed

iHub Global's list is 1,304,352 positions, 72 levels deep, with 99.995% of it
hanging off a single leg. The module was built for a few thousand; that file
forced three changes, all of them load-bearing.

**Activation codes are keyed HMAC-SHA256, not bcrypt.** At bcrypt cost 12, a
million hashes is over a week of CPU before a single row lands. These are
randomly generated 12-character tokens (~62 bits), not human-chosen passwords,
so a work factor buys nothing — the same reasoning behind Laravel storing
Sanctum tokens as a plain SHA-256. Keyed with APP_KEY so a database dump alone
cannot be checked against offline. See `ActivationCode`, which also exposes the
Postgres expression the bulk path uses, and note that **rotating APP_KEY
invalidates every unclaimed code**.

**Nothing loads rows into PHP.** The parser streams the CSV and bulk-inserts in
chunks of 2,000; the validator asks every question as an aggregate; the
committer is a dozen set-based statements. A collection of 1.3M Eloquent models
is several gigabytes and a walk over it is minutes.

**Paths are built one level at a time.** Validation numbers every row by depth
(`placement_depth`, `enrollment_depth`); the commit then runs one UPDATE per
level — 72, not 1.3 million. The depth pass doubles as cycle detection: with
orphans already ruled out, a row that no level reaches is a row on a loop.

Measured on dev: parse ~7 min, validate ~14 min, commit in the same order of
magnitude. `users` gains a partial index on
`(partner_import_id, external_user_id)` — every statement after the insert joins
on it, and without it the parent-resolution pass has no index to use.

Big imports run from the command line, not the admin screen:
`php artisan partners:import <slug> --stage|--link|--commit`. A 60MB upload
through the request path and a multi-minute transaction behind a proxy timeout
are both bad ideas. The admin screen remains where the review and the leg
connections happen.

## 4. The pipeline

```
CSV  →  partner_import_rows  →  validate  →  connect the legs  →  commit  →  users
        (staging, nothing real)   (whole tree)   (by hand)        (permanent)
```

| Stage | Class | What can go wrong here |
|---|---|---|
| Parse | `SpotImportParser` | Missing required column, unreadable header, duplicate ID. Nothing else — reading is not judging. Also where unrecognised columns are discarded (§ 0). |
| Validate | `SpotImportValidator` | Orphan parent, cycle, duplicate or too-short code, an ID this company already imported. Warns on legs nobody has connected. |
| Connect | admin review screen | Entirely by hand. The file names no Quantum accounts, so there is nothing to auto-resolve — an admin types the partner each leg belongs under. |
| Commit | `SpotImportCommitter` | One transaction, parents first, or nothing. |

**Legs.** A row with a blank `external_parent_id` is the top of a leg and has no
parent inside the file. Those are the rows an admin connects to a partner already
in our system, and they are the only irreversible decision in an import. A batch
with an unconnected leg cannot commit — committing it would make that leg the
root of its own tree, invisible to the upline that was promised it.

**Ordering.** Rows are committed breadth-first from the legs, never in file
order: a child's path is built from its parent's. A file that lists children
above parents is somebody else's export ordering, not an error.

## 5. Activation codes

The partner company issues them; we never generate them for an import. A code we
invented is one their people have never seen.

They arrive in plaintext in the CSV, are held in plaintext in staging while an
admin can still check a row against a support call, and are **hashed on the way
into `users` and cleared from staging at commit**. After a batch commits, nobody
— including us — can read the codes back out of this system.

The uploaded file is the exception and needs a human policy: it sits on the
private disk with a live column of codes in it, so it should be deleted once the
batch is committed. The upload form says so; nothing enforces it yet.

Support path for a lost code: an admin issues a replacement from the spots board.
It is displayed once, in a flash message, and stored hashed.

### Why the lockout is per spot

The user IDs are not secret. They are printed on the partner's own material, they
run in sequence, and anybody who was ever a member of that company has a list of
them. The code is the only thing standing between a stranger and somebody else's
position *and its downline*.

Route throttling limits how fast one IP can guess. It does nothing about a
hundred IPs grinding the same high-value position, which is the attack that
matters here. So a spot locks itself after five wrong codes and stays locked
whoever is asking. Unknown IDs and wrong codes give the identical answer, and the
unknown-ID branch burns a real bcrypt verify, so the endpoint cannot be used to
sift the real IDs out of a guessed list.

## 6. Telling the partner back: the claim webhook

The import is one-way and carries nothing personal. The return leg is the
opposite shape — the member has an account with us now, and the partner wants to
know which of their people came across.

`spot.claimed` is POSTed to the company's endpoint when a position is claimed.
Signed HMAC-SHA256 over `"{t}.{body}"` in a `Q3-Signature: t=…,v1=…` header —
the same scheme Stripe uses, chosen because every partner's engineers have
already written the code that verifies it, and a scheme they recognise is one
they actually check.

| | |
|---|---|
| Fires from | `SpotClaimService::claim()`, inside `DB::afterCommit` |
| Built and recorded by | `PartnerWebhookDispatcher` |
| Delivered by | `DeliverPartnerWebhookJob` — 30s / 5m / 30m / 1h, then failed |
| Ledger | `partner_webhook_deliveries`, one row per event, payload frozen at send |
| Partner-facing doc | `resources/templates/partner-webhook-guide.md` |

**`afterCommit`, not inline.** A delivery row written inside the claim's
transaction is visible to a queue worker before the claim is, so the worker
reads a spot that is still holding and sends the partner the wrong state. And a
partner's endpoint must never be able to fail or delay a claim — the member is
standing in front of the form. `PartnerWebhookTest` pins both.

**The payload is stored, not rebuilt.** A replay sends the same `event_id` and
the same bytes, so a partner keying idempotency on the id recognises it as a
repeat rather than a second claim. The consequence is that a replayed event
carries the `team_size` from the moment of the claim, not today's — which is
documented, and is the right trade for a stable idempotency key.

### `webhook_include_contact`

Off by default, and the one setting in this module that discloses personal data.

Without it the event carries their identifier, timestamps and the position —
what the partner needs to reconcile their own list, and nothing they did not
already know. With it, the member's name, email and phone go too. Those are
details the member gave *us*, so sending them to a third party is a decision
somebody makes with the claim page's wording and the privacy policy updated to
match, not a default nobody chose.

`PartnerWebhookTest` asserts both halves: absent by default, present when set.

### The first partner: iHub Global

Created by `PartnerCompanySeeder`, not typed into the admin, because the claim
page is a public co-branded URL — the slug goes in iHub's own mailings, the logo
is a committed asset at `public/assets/images/partners/ihub/ihub-dark.png`, and
the wording was agreed with them. All three should arrive with a deploy and be
reviewable in a diff.

The seeder is idempotent and deliberately does **not** touch `is_active` or any
webhook setting: those are switches somebody threw on purpose, and a deploy must
not throw them back. `IhubGlobalTest` pins that, and that the logo file is
actually where the record says it is.

`PartnerCompany::logoUrl()` resolves both kinds of path — `assets/…` for a
committed brand file, anything else for an admin upload onto the public disk.

## 6a. Merging: the founder case

A founder has a Quantum position from before any of this, and an iHub position
with an organisation under it. Both are theirs, and they should end up with one
account and one team.

`SpotMergeService` retires the imported position and re-hangs everyone beneath
it under the surviving account. **It does not move a position** — that
distinction matters, because "placements are permanent" is the rule the import
is built around. What moves is a subtree, deliberately, with both sides
belonging to the same person.

ltree makes it one statement per tree:

```
new = survivor.path || subpath(descendant.path, nlevel(spot.path))
```

Two ways in:

| | |
|---|---|
| The claim flow | A signed-in partner enters their ID and code, and the next screen offers "Add it to my &lt;name&gt; account". Typing the activation code is better proof of ownership than anything an admin could act on later, which is why this path may merge a position that was unclaimed a moment ago. |
| Admin → Partner Spots | For a founder who already claimed as a separate account. Requires the position to be claimed already. |

What it refuses: merging into an account that sits inside the subtree being
moved (it would become its own ancestor), merging twice, and — on the admin
path — merging an unclaimed position, which would hand somebody a downline on
staff's say-so.

The absorbed row is kept, not deleted: it holds an `external_user_id` the
partner will quote at us for years. Its email is released so the person can use
that address on the account that survived. The partner is told, with
`merged_into` in the `spot.claimed` event — otherwise they reconcile their tree
against ours and find a downline apparently reparented for no reason.

## 7. What people see

| Screen | Shows |
|---|---|
| `member.network` (My Team) | Members only, plus a count of unclaimed positions below you and a link. |
| `member.network.spots` | The partner company's identifier for each waiting position, and nothing else, because there is nothing else — see § 0. |
| `admin.users.index` | Members only. |
| `admin.partners.spots` | The one place holding spots are listed, with the claimed/unclaimed split per company, and where a claimed position is merged into another account. |
| `admin.dashboard` | `total_users` counts members; unclaimed spots are their own number. |

## 8. Files

```
app/Services/Partner/
  SpotImportTemplate.php    CSV contract — the one definition the parser, the
                            download and the checked-in template all come from
  SpotImportParser.php      CSV → staging
  SpotImportValidator.php   whole-batch checks, re-runnable
  SpotImportCommitter.php   staging → genealogy, one transaction
  SpotClaimService.php      verify, claim, reissue
  SpotMergeService.php      fold a position and its downline into another account
  ActivationCode.php        how a code is hashed, in PHP and in SQL
  PartnerWebhookDispatcher.php  build, sign and record the outbound event

app/Jobs/DeliverPartnerWebhookJob.php   delivery, off the request path

app/Services/Genealogy/
  GenealogyService::placeUnder()      explicit placement, for imports only
  GenealogyService::compress()        the compression rule in § 3
  EnrollmentService::enrollImported()

resources/templates/partner-spot-import-template.csv   generated; regenerate with
resources/templates/partner-spot-import-template.md    php artisan partners:import-template
resources/templates/partner-webhook-guide.md           hand-written; see below
```

The webhook guide is written by hand rather than generated, but it is not
allowed to drift: `PartnerWebhookTest` walks every field of a real payload and
fails if the guide does not mention it. A field renamed in the dispatcher and
not in the guide would otherwise break a partner's integration with no warning
on our side.

`ImportTemplateTest` fails if the checked-in template drifts from the code that
reads it. A partner filling in a stale template is an import that silently drops
a column — most damagingly the parent column, which would flatten an entire
organisation into a list of roots.

## 9. Open questions

- **Billing.** A claimed spot goes to the standard enrollment choice (start now /
  wait for commissions). No separate path for imported partners.
- **Commission compression is implemented in the genealogy queries** (§ 3), but
  `CommissionService` does not yet climb `nearestActivatedAncestor()` — it has no
  upline walk today. When one is added, that is the method to use.
- **Spots never claimed.** No expiry policy yet. A position held indefinitely by
  nobody is a permanent gap in a leg; decide a horizon before the first import
  ages a year.
- **The uploaded file.** Deleted by hand today. Worth a scheduled purge once the
  first real import has gone through and we know how long a batch stays under
  discussion after commit.
- **Webhook delivery retention.** `partner_webhook_deliveries` keeps the full
  payload forever, and with `webhook_include_contact` on that payload contains a
  member's contact details. Needs a retention window before the first company
  has that switch on for long.
- **The first import is documented separately.** `ihub-import-runbook.md` is the
  procedure for putting iHub's list into production, including the leg
  connections, timings, and the rollback window.
- **No `spot.claimed` for a position claimed some other way.** There is only one
  path to claiming today, so the event fires from one place. If an admin is ever
  given a "mark this claimed" action, it has to fire too.
