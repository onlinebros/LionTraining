# Request Assistant

Super admins describe a change they want. A read-only Claude reads the project with them,
asks questions, and drafts a plan. The plan is decided on dev. **Nothing the assistant does
changes the application.** Approving a plan records a decision; building it is a separate,
normal piece of work (download the plan as Markdown and hand it over).

Built 2026-10-12.

## The owner's rules (2026-10-12)

- **Super admins only** can request. Michael Stern (michaelstern333@gmail.com) is one.
- **Requests are kept in the live database** (app.q3.life), so they are backed up with
  everything else and quick to read there.
- **Decisions are made only on dev** (Admin → Review Requests on q3.onlinebros.com).
- **The AI runs only on dev, on the owner's Claude subscription** (the `ai` CLI login, Max
  plan). It never uses an API key. The live site runs no AI.

## How it fits together

```
 live (app.q3.life)                         dev (q3.onlinebros.com)
 ─────────────────                          ───────────────────────
 Admin → Change Requests, lightbulb   ◄──── relay: claim waiting turn, run Claude CLI,
 change_requests tables (the record)  ◄────        post the answer back
 /api/request-assistant/* (token)     ◄──── Admin → Review Requests: read, comment, decide
```

Dev always calls out; the live site never calls dev.

- **On live:** a super admin starts a request from the lightbulb pop-up in the header,
  which links it to the page they were on, or from Admin → Change Requests. A turn for the
  assistant is marked as waiting in the row (`assistant_pending`). Every super admin sees
  every request; only its author can continue it. The page polls until the answer lands.
- **The relay** (`php artisan request-assistant:relay`, run as `ai` by
  `scripts/request-assistant-worker.sh`, `@reboot` in `ai`'s crontab) checks every 5 s.
  It claims the oldest waiting turn: live builds the prompt from its own record and also
  sends a whole-conversation fallback for when the CLI session cannot be resumed. The relay
  runs the CLI and posts `complete` or `fail`. An unanswered claim is offered again after
  25 minutes.
- **Review on dev:** Admin → Review Requests (super admins; exists only once dev is
  connected). Decisions and comments are written to live, under the dev reviewer's name.
  Dev keeps no copy.

## Connecting the two

On live: `php artisan request-assistant:token`. It prints the command to run on dev:
`php artisan request-assistant:connect https://app.q3.life <token>`.

- Live keeps only the token's SHA-256 (site setting `request_assistant_api_token_hash`).
- Dev keeps the URL and token encrypted with its APP_KEY (site setting
  `request_assistant_upstream`).
- Re-issuing replaces the token at once. `--revoke` on live closes the API;
  `connect --disconnect` on dev stops the relay and the review screens.
- **Dev's database has been wiped before.** If that happens again, re-run both commands.
  The requests themselves are safe, because they live on live.
- With the token, someone can read and write request conversations and decisions, but not
  member data or code.

## Where things are

| | |
|---|---|
| Live screens | `Admin\ChangeRequestController`, `admin/change-requests/*`, `partials/request-assistant-{button,modal}` |
| Live rules | `Services/RequestAssistant/ChangeRequestService` (ownership, busy, hourly limit, plan before submit) and `TurnService` (claim / complete / fail, prompt building) |
| API | `routes/api.php` `request-assistant.*`, `Api\RequestAssistantController`, `RequireRequestAssistantToken` |
| Dev | `request-assistant:relay`, `Admin\ReviewRequestController`, `admin/review-requests/*`, `Services/RequestAssistant/Upstream` |
| The CLI call and guardrails | `Services/RequestAssistant/ClaudeRunner`, `scripts/request-assistant-guard.php` |
| Project copy | `Services/RequestAssistant/Workspace` → `/home/ai/.cache/q3-request-assistant/workspace` |
| What the assistant is told | `Services/RequestAssistant/Prompts` |
| Config | `config/request-assistant.php` (model, limits, CLI path, what is left out of the copy) |

## Guardrails: each holds on its own

1. **A copy, not the repo.** `git archive HEAD` of tracked files only: no `.env`, `storage/`,
   `vendor/`, uploads, untracked work. Symlinks deleted. Excluded by config: `scripts/`
   (committed Kartra password), `deploy/`, `memory-bank/deployment.md`, `.github/`,
   `starter-kit/`, `template/`, and media/archives. **It lives outside the repository**:
   inside it, the repo's `.gitignore` made the CLI's Grep skip every file (found and fixed
   2026-10-12).
2. **Three tools.** `--tools Read,Grep,Glob`. No shell, edit, web, MCP, skills or subagents.
3. **Guard hook** on every tool call: refuses any path outside the copy (lexical and
   realpath), any `.env*`, absolute or `..` globs, and any other tool. Fails closed.
4. **dontAsk permission mode**: nothing outside the cwd is pre-approved, so it is refused.
   Verified with the hook removed: out-of-tree Read/Grep/Glob are still denied.
5. **No inherited config**: `--setting-sources ""` (the `ai` account's settings, hooks,
   permissions, CLAUDE.md are ignored), auto-memory off, `--strict-mcp-config`.
   **Never add `--bare`**: it skips hooks, which switches off layer 3.
6. **Clean environment**: `env -i` with HOME, PATH, LANG only. No DB password or API keys
   are passed, and no `ANTHROPIC_API_KEY` can switch billing off the subscription.

Not an OS sandbox. The CLI still runs as `ai`, which can read most of the server. A dedicated
unix user that can read only the copy (or bubblewrap) needs root. Unprivileged user namespaces
are blocked by AppArmor on this host.

## Q3-AI Assistant on support tickets (2026-10-22)

The same relay also helps support staff answer tickets. On a ticket (Admin → Support →
a ticket), **Ask Q3-AI for a draft** queues a turn. Q3-AI reads the ticket against the
code and notes, and answers to fixed headings: a **verdict** (`ANSWERABLE`,
`MANUAL_ACTION` with the admin steps, or `NOT_BUILT`), *what the system does today*, a
plain-text **draft reply**, and for `NOT_BUILT` the **change needed**. Staff can ask it
to adjust ("shorter", "they already tried X"); every answer repeats the headings.

Owner's choices (2026-10-22):
- **All support admins** can use it, not only super admins.
- **The member's identity is stripped before the ticket leaves the server**
  (`SupportAssistant\Redactor`): their name becomes `[member]`, and emails, numbers of 9+
  digits and URL query strings are masked. Staff names are sent as "Support staff". The
  draft says `{name}`, filled in with the first name when staff press *Use this draft* or *Copy*.
- **`NOT_BUILT` → a "Start a change request" button** (super admins; others see the
  finding and are told to ask one). It opens a change request from the redacted ticket and
  the finding, which the Request Assistant then works into a plan. One per ticket.

How it runs:
- Kept in `support_assists` / `support_assist_messages` (one conversation per ticket,
  shared by all staff); `support_tickets` is not altered.
- The panel shows only where a relay will answer: on dev once connected, on live once a
  token is issued (`SupportAssistService::available()`).
- The relay answers **dev's own tickets locally first**, so it can be tried on dev, then
  claims from live with `kinds: [support]`. Live hands out support turns **only** to a
  relay that sends that (an older relay would post the answer to `relay/{id}`, a change
  request's path). Waiting change requests go before support turns. Answers go to
  `relay/support/{id}/complete|fail`.
- Same CLI, same six guardrails, own role (`SupportAssistant\Prompts::system()`, passed
  to `ClaudeRunner::run()`). It never sees the member's account, payments or any live
  data, only the ticket text, so `MANUAL_ACTION` answers say what staff should check.
- `support_hourly_limit` (40 questions per person per hour) in `config/request-assistant.php`.
- First real turn (dev, sample ticket TKT-00001): 51 s, 18 steps, ≈$0.19 at API prices.

## Things to know

- Requesters can see the code and memory-bank notes through the assistant, including the
  Stripe/MLM, comp plan and vendor notes. Add paths to `exclude` to hide more.
- It shares the owner's subscription rate limits with development work. `cost_usd` per
  reply is what it would cost at API prices, not a bill. Model `sonnet`, 30 messages per
  person per hour.
- It reads **committed** code on dev. Uncommitted work is invisible to it.
