# Proposal Studio

Super admins work through a prospect company with an AI assistant that knows the offering we
present (PlasmaGuard first). The assistant drafts a plan, emails and a proposal page. The
prospect reads that page in a private portal, sends details, asks questions and asks for a
quote. Phases 0–4 were built on dev on 2026-10-20/21. **It ships switched off
(`PROPOSAL_STUDIO_ENABLED`).**

Plan, owner decisions and the PlasmaGuard question tracker:
https://claude.ai/artifact/TiVvpWnL7bb1cd64B5SdTY

## Owner decisions (2026-10-20)

| | Decision | Where it lives |
|---|---|---|
| D1 | Admin AI runs on dev, on the owner's CLI subscription. Prospect-facing AI uses a Claude API key on live. | `ClaudeRunner` (CLI, no tools); `PortalAssistant` (API) |
| D2 | Super admins only, to start. | `super_admin` middleware on every studio route |
| D3 | The portal is for invited emails, opened with a sign-in link. | `PortalInvite`, `PortalService` |
| D4 | Only vendor-confirmed list prices are shown, as estimates. | Proposal prompt + "Investment" section disclaimer |
| D5 | The vendor reviews the first few proposals, then spot-checks. | `proposal-studio.vendor_review_first` (3), `ProposalService::needsVendorReview` |
| D6 | The vendor sees a prospect's details once a proposal is published. | Product Partner → Proposals. **This is an exception to the counts-only Pipeline page, for studio prospects only.** |

## The pieces

- **Offerings** (`offerings`): a product line, with a summary, guidance for the assistant,
  claims never to make, a discovery checklist, and `vendor_key` (the slug in
  `config/vendors.php` that links it to the Product Partner portal). A new line is a new row.
- **Knowledge library**: `offering_documents` holds PDFs (`pdftotext`), .docx, .pptx (slides
  plus speaker notes), text and HTML. Each document is either internal or shareable, and is
  or is not read by the assistant. Text is cut into `knowledge_passages`. While a library fits
  in `context_chars` (150k), documents are sent whole; past that, `Knowledge::search` (BM25 in
  PHP) picks the matching passages.
- **Claims** (`knowledge_claims`): "Find claims" asks the assistant for quotes from a
  document. **A quote is kept only if it appears word for word in the document**
  (`KnowledgeClaim::isQuotedFrom`, which ignores whitespace, quote marks and dashes). Footnotes
  are held to the same rule. An admin approves or rejects claims, and never rewords them.
  Approved claims are the only results that reach a prospect, and proposals render them from
  the row by id, never from AI text.
- **Ask the library** (`library_questions`): admin Q&A with citations.
- **Prospects**: intake → discovery chat. Each reply carries a `profile` block that feeds the
  "What we know" panel. "Draft plan and emails" produces a plan and three emails.
- **Proposals** (`proposals`): versioned. The assistant drafts JSON (`ProposalDraft::clean`
  keeps only approved claim ids). The admin edits it, previews it, then publishes, which goes
  through the vendor while D5 applies. A new version supersedes the old one.
- **Portal** (`/proposal/{ref}`, `PortalController`): sign-in links valid for 14 days and
  reusable, because mail scanners open links first. Prospects can see their situation, the
  plan, how it works, the claims, shareable documents, a details form with uploads,
  questions, and quote/survey requests. Everything they send is copied into the studio
  conversation (`ROLE_PROSPECT`) and emailed to the prospect's creator. `portal_events` logs
  views and downloads.
- **Portal assistant**: answers on the spot when `PROPOSAL_STUDIO_ANTHROPIC_API_KEY` is set
  (Anthropic PHP SDK, `claude-opus-5-5`, effort `medium`, capped at 40 answers per portal per
  day). It answers only from approved claims, shareable documents and that prospect's
  proposal, and hands off when unsure. Without a key, every question goes to the admin, who
  answers by email. Handed-off questions appear under the offering's "Prospect questions" tab
  as library gaps.

## The job queue and the relay

Every piece of AI work is a `studio_jobs` row (`JobQueue`). There are five kinds: prospect
reply, prospect draft, proposal draft, claim extraction and library answer. Each has a handler
in `Services/ProposalStudio/Jobs` that builds the prompt and applies the answer **on the server
that keeps the data**.

`proposal-studio:work`, run as `ai` from `scripts/proposal-studio-worker.sh`, works through
dev's own jobs. When connected, it also takes the live site's jobs:

- **On live:** `php artisan proposal-studio:token` prints a `proposal-studio:connect` line.
- **On dev:** run that line. The token is stored encrypted, and only its SHA-256 is kept on
  live.
- The worker posts to `/api/proposal-studio/relay/{claim,complete,fail}`. Only the prompt and
  the answer travel; live never calls dev.

The token is separate from the Request Assistant's, because this one reaches prospect data.
A job claimed and not answered within 12 minutes is offered again; after 3 attempts it fails
and the prospect sees an error. The CLI timeout is 540 s, because a 35k-character deck took
250 s to read for claims.

The CLI runs with **no tools** (`--tools ""`), under `env -i`, with no settings sources, no MCP
and no saved session, from `~/.cache/q3-proposal-studio/empty`.

## Data on dev (2026-10-21)

- **Documents:** 16 PlasmaGuard files from `storage/app/private/training/plasmaguard`, imported
  with `proposal-studio:import`. Eight are image-only PDFs, so their text is the other
  session's **model transcription**, not the vendor's own text layer. Check the wording
  against the PDF before approving claims from those (Docs 1, 3, 5, 7, 9, 10, 11, 13).
- **Pathogen results (Docs 15, 16):** both are headed "DRAFT FOR APPROVAL, SOURCE MATERIAL
  INCLUDES CONFIDENTIAL CONTENT — External distribution rights must be confirmed before
  release." Keep them internal, and approve no claims from them until PlasmaGuard confirms.
- **Training transcripts:** imported with
  `--kind=training` from the other session's `scratchpad/transcripts`. Re-run the import as
  more finish; it skips files it already has.
- **Claims:** 28 draft claims from Docs 12 and 16. **None are approved yet.**

## Going live (app.q3.life)

1. Commit and deploy. The migrations add tables and touch no `users` index.
2. Set `PROPOSAL_STUDIO_ENABLED=true` in the production `.env`, and optionally
   `PROPOSAL_STUDIO_ANTHROPIC_API_KEY`. Then run `php artisan config:cache`.
3. On prod, run `php artisan proposal-studio:token`. On dev, run the `connect` line it prints.
4. Load the library on prod: upload through Offerings, or copy the files up and run
   `proposal-studio:import`. Then review the claims there; live has its own data.
5. Give PlasmaGuard's reviewers Product Partner access to `plasmaguard` (D5/D6).
6. Portal emails go out through Resend from the production `MAIL_FROM_ADDRESS`.

## Not built yet

- Phase 5: selling partners and Product Partners creating proposals.
- Linking a prospect to a CRM contact and the referring partner (the columns exist).
- Pricing documents and sizing rules from PlasmaGuard (questions Q03, Q05 and Q15).
