# iHub Global import — production runbook

The procedure for putting iHub Global's 1,304,352 positions into the live
Quantum 3 Solution genealogy.

**Read this whole document before starting.** The commit step is irreversible by
design: positions are permanent, and there is no operation that moves one. The
rollback in § 7 works only in the window before anybody claims.

---

## 1. What the data is

Rehearsed in full on `q3.onlinebros.com` (dev) on 2026-09-18 against the file
`quantum3-users-partner-20260917-123610.csv`, 61 MB.

| | |
|---|---|
| Rows | 1,304,352 |
| Unique IDs | 1,304,352 — no duplicates |
| Activation codes | 1,304,352 distinct, all 12 characters |
| Blank IDs / codes | none |
| Orphan parents | none |
| Cycles | none |
| Deepest level | 72 |
| Legs (blank `external_parent_id`) | 40 |
| Rows with warnings | 4,605 |

The file carries the four agreed columns and no personal data.

### The shape that matters

**One leg is the entire company.** `iHub-2` carries 1,304,282 of the 1,304,352
positions — 99.995%. The other 39 legs total 70 positions between them, and 33
of those are a single position each.

Connecting `iHub-2` to the wrong account puts iHub's whole organisation in the
wrong person's downline, permanently. Everything else in this runbook is
routine; that one decision is not.

### The warnings

4,605 rows name an `external_sponsor_id` that is not in the file. iHub tracks
recruitment across a system wider than the slice they exported, so this is
expected. Those positions are placed exactly where the parent column says; the
position directly above is recorded as the sponsor. No action needed, but it
means our enrollment tree will not match iHub's sponsor column exactly for
those rows, and somebody will eventually ask why.

## 2. Before you start

- [ ] The file is on the production server, readable, and its size and row
      count match what iHub sent. `wc -l` should report 1,304,353 (the header).
- [ ] `pgcrypto` and `ltree` extensions exist on the production database. The
      migrations create them; `php artisan migrate` has been run.
- [ ] The iHub partner company row exists: `php artisan db:seed --class=PartnerCompanySeeder --force`
- [ ] **The claim page is OFF.** `is_active` must be false on the iHub company
      until the commit finishes. A live page with no positions behind it tells
      every iHub member their details do not match.
- [ ] **The webhook is OFF.** The import itself sends nothing, but do not enable
      it until iHub confirms their receiver is ready.
- [ ] You know which Quantum account each of the 40 legs belongs under, in
      writing, agreed with iHub. Especially `iHub-2`.
- [ ] **The database cluster has room.** This is the one that will stop you —
      see § 2a. Measured on dev, a completed import needs roughly **6.5 GB**,
      and production's managed cluster started at 12 MB.
- [ ] A database backup taken within the last hour, and you have checked it
      restores.
- [ ] Nobody is mid-signup. Pick a quiet window: the commit takes an exclusive
      chunk of database time (see § 5).

## 2a. Storage — check this before anything else

Measured on the dev rehearsal, after 1,304,352 positions:

| | |
|---|---|
| `users` table | 2,188 MB |
| `users` indexes | 2,010 MB — mostly the two ltree GIST indexes |
| `partner_import_rows` (staging) | 1,533 MB |
| `sponsorships` | ~400–600 MB, one row per position |
| **Total** | **~6.5 GB** |

On top of that, the commit is a single transaction writing several gigabytes, so
WAL grows until it checkpoints. Budget meaningfully more than 6.5 GB free, not
6.5 GB exactly.

**Resolved for the first import (2026-09-18).** The cluster was on
DigitalOcean's smallest node — 1 GB RAM, 10 GB disk — which 6.5 GB of data plus
the WAL from a single multi-gigabyte transaction would not have fitted safely.
The failure mode is bad: a full DigitalOcean cluster goes read-only, which takes
`app.q3.life` down, and rolling the failed transaction back needs disk of its
own.

The owner moved it to 2 GB RAM with more disk **and turned on storage
auto-scaling at 80%**, which is what actually removes the risk — the cluster
grows itself rather than stopping. Leave that on.

For any future partner import, the check is the same: estimate ~5 KB of database
per position and confirm there is several times the transaction's size free.

Staging alone is 1.5 GB and is reversible (`DELETE FROM partner_import_rows`),
so the file can always be checked in place before committing to anything.

### Worth revisiting later

4.2 GB for 1.3M positions is ~3.2 KB per row, and half of it is index. The two
ltree GIST indexes are what make every downline query fast, so they earn their
place, but if storage becomes the binding constraint the first thing to look at
is whether `enrollment_path` needs its own GIST index on this dataset — for this
import the enrollment tree is the placement tree.

## 3. Timings, measured on dev

Dev is a smaller box than production; treat these as an upper bound but do not
assume much better.

| Stage | Time | Notes |
|---|---|---|
| Parse (`--stage`) | ~7 min | Streams the file, 2,000 rows per insert |
| Validate | ~14 min | 144 depth passes, two trees × 72 levels |
| Commit | ~1 hr on dev | One transaction. **See the warning below.** |

**The commit timing above was measured without the index that makes it fast.**
The dev rehearsal ran before `2026_09_18_000001_index_imported_users_for_commit`
could be applied — the import's own transaction was holding the lock the index
needed. Every statement after the initial INSERT joins on
`(partner_import_id, external_user_id)`, and without that index the 144 path
passes each scan 1.3M rows.

Production already has the index: it went on with the deploy on 2026-09-18.
Before running the import, confirm it is there —

```sql
SELECT indexname FROM pg_indexes
 WHERE tablename = 'users' AND indexname = 'users_partner_import_lookup_idx';
```

— and if it is missing, run `php artisan migrate --force` and check again
**before** starting. Adding it afterwards does not help; it cannot be added
during.

Plan for the commit taking tens of minutes and hold the window open longer than
you think you need. It is not a job you start and walk away from.

## 4. Stage and check

```bash
cd /path/to/backend

php artisan partners:import ihub --stage \
  --file=/absolute/path/to/quantum3-users-partner-20260917-123610.csv
```

It prints a summary and every leg with its subtree size. **Stop and read it.**

Expected: status `validated`, 1,304,352 ready, 0 with errors, 40 legs, deepest
level 72. If any of those differ from § 1, the file is not the one that was
rehearsed — stop and find out why before going further.

Note the import id it prints. Everything below uses it.

## 5. Connect the legs

Each leg is connected by hand, naming a Quantum account by email or numeric ID:

```bash
php artisan partners:import ihub --import=<ID> \
  --link=iHub-2:founder@quantum3solution.com \
  --link=iHub-U1:someone-else@example.com
```

Repeat until `Legs not connected` reads 0. The command re-checks after every
change and reprints the table, so the last run before committing is also your
final review.

**Check `iHub-2` twice.** It is 99.995% of the import.

Anything not connected blocks the commit — that is deliberate, and it is the
last safety net. An unconnected leg committed would become the root of its own
tree, invisible to the upline it was promised to.

## 6. Commit

```bash
php artisan partners:import ihub --import=<ID> --commit
```

It asks for confirmation. There is no undo after this.

What it does, as a single transaction:

1. `INSERT ... SELECT` — 1.3M `users` rows, activation codes hashed by pgcrypto
2. two `UPDATE ... FROM` — resolve `placement_parent_id` and `sponsor_id`
3. 72 + 72 `UPDATE`s — build the ltree paths one level at a time
4. `INSERT ... SELECT` — the legacy `sponsorships` rows
5. one `UPDATE` — close the staging rows and wipe the plaintext codes

Because it is one transaction, it holds locks on `users` throughout. Other
writes to `users` — signups, claims, profile edits — will wait. This is the
reason for the quiet window.

If it fails, nothing is imported. Fix the cause and run it again.

### Afterwards

```bash
# Sanity check the shape that landed.
php artisan partners:import ihub --import=<ID>
```

Confirm in the admin at **Partner Spots → Claimed / Unclaimed**: 1,304,352
unclaimed, 0 claimed.

Spot-check a few positions against iHub's own view of the tree before anybody
can reach the claim page.

## 7. Rollback

**Only valid before anybody has claimed a position.** Once a claim lands, the
account is real and this is not a rollback, it is deleting a member.

```sql
BEGIN;
-- Check first. If this is not 0, STOP: someone has claimed.
SELECT count(*) FROM users WHERE partner_import_id = <ID> AND claimed_at IS NOT NULL;

DELETE FROM sponsorships WHERE sponsored_id IN (SELECT id FROM users WHERE partner_import_id = <ID>);
DELETE FROM users WHERE partner_import_id = <ID>;
UPDATE partner_imports SET status = 'validated', committed_at = NULL, committed_rows = 0 WHERE id = <ID>;
UPDATE partner_import_rows SET status = 'valid', created_user_id = NULL WHERE partner_import_id = <ID>;
COMMIT;
```

Note what this does **not** restore: the plaintext activation codes, which the
commit wiped from staging. A re-import needs the original file again. Keep it
until you are confident.

## 8. Going live

In order:

1. **Confirm the numbers** on the spots board.
2. **Turn the claim page on** — admin → Partner Spots → Companies → iHub Global
   → "Claim page is live". It is then reachable at
   `https://app.q3.life/partner/ihub`.
3. **Set the webhook secret**, agreed with iHub, on the same screen. Leave
   "Send claim events" off until they confirm their receiver is ready, then
   send a test event and check it lands.
4. **Give iHub the deep-link format** so their mailing fills the form in:
   `https://app.q3.life/partner/ihub?uid=<their id>&code=<their code>`
5. **Founders claim and merge** — see § 9.
6. Delete the CSV from the server. It is a list of live credentials.

## 9. Founders: claim, then merge

A founder who already has a Quantum position and also has an iHub position
should end up with **one account and one team**.

The good path, which needs no administrator:

1. The founder signs in to their existing Quantum account.
2. They open `https://app.q3.life/partner/ihub` and enter their iHub ID and
   activation code.
3. The next screen offers **"Add it to my &lt;name&gt; account"**. Taking it
   retires the iHub position and re-hangs its entire downline directly under
   their founder position.

If a founder has already claimed their iHub position as a *separate* account,
an admin can still merge from **Partner Spots**: find the claimed position, type
the surviving account's email into the merge box, confirm.

Either way this is the only operation in the system that rearranges a live
genealogy. It is narrow on purpose — the position being merged must already be
claimed, and the account absorbing it cannot sit inside the subtree being moved.

## 10. Known consequences to expect

- **Team numbers do not move.** Unclaimed positions are not counted anywhere: not
  in My Team, not on the admin dashboard, not in the user list. The numbers only
  change as people claim.
- **Levels compress.** A member who claims under a chain of unclaimed positions
  appears on their nearest activated upline's level 1, and shifts down as the
  positions above them get claimed. This is deliberate — see
  `partner-spot-import.md` § 3.
- **The admin user list stays clean.** 1.3M holding rows do not appear in it.
- **`users` grows by 1.3M rows.** Watch the database size and the ltree GIST
  index; nothing else in this application has been asked to hold a tree of this
  size.
