# B2B accounts and client microsites

Built on dev on 2026-10-28, on top of the Proposal Studio (`memory-bank/proposal-studio.md`).
Like the studio, it is behind `PROPOSAL_STUDIO_ENABLED`, and **super admins only** (D2).

The owner asked for a way to bring on large companies and manage those relationships. They also
wanted customisable B2B marketing built per client by an AI assistant that grows from what we
learn, shared on a page the client opens securely.

## Owner decisions (2026-10-28)

| | Decision | Where it lives |
|---|---|---|
| A1 | Clients get in by **invitation, plus request access**. Anyone invited can ask for a colleague; an admin approves before a link is sent. | `MicrositeService::requestAccess / approveRequest` |
| A2 | **Super admins only** build accounts and microsites. Partners come later (studio Phase 5). | `super_admin` on every route |
| A3 | **Co-branding:** we upload the client's logo and pick their colour. It is shown only on their private site. | `business_accounts.logo_path`, `brand_color` |

## The pieces

- **Business account** (`business_accounts`): the company. Its stage (target → engaged →
  customer, or dormant), an owner, a next step with a date, and notes, which the assistant reads.
  Named "business" so it is not confused with Stripe Connect accounts.
- **People** (`business_contacts`): each person has a part in the decision (decision maker,
  champion, finance, facilities, technical). Being a contact gives **no** access to the site.
- **Timeline** (`business_activities`): notes, calls, meetings, emails and tasks (a task is a
  line with `due_on`, done when `done_at` is set). It also records `client` lines (sign-ins,
  comments, downloads, access requests) and `system` lines (stage changes, publishing).
- **Opportunities** are Proposal Studio prospects, one offering each, linked by
  `prospects.business_account_id`. `StudioService::start` creates an account for any prospect
  started without one. The migration backfilled one account per existing prospect; dev had none.
  Marking an opportunity won makes the account a customer.

## The microsite (`/client/{ref}`)

- **Sections** (`microsite_sections`) are kept in order. The kinds are welcome, challenges,
  solution, evidence, proposal, documents, faq, next_steps and custom. Each draws on one
  opportunity, for its offering's library and claims. "Add the usual sections" builds the
  outline.
- **AI drafting** uses the `microsite_section` job kind on the existing queue and relay
  (`Jobs\MicrositeSectionDraft`, `Prompts::micrositeSection`). The prompt carries the company and
  the roles of its people (no contact details), the opportunity's profile and conversation,
  their published proposal, the other sections, and their people's comments on this section.
  **Claims stay verbatim:** the text may state no results, and claims are ids, filtered to the
  approved claims of the section's offering and rendered from the library.
- **Publishing is per section.** Clients see only published sections. They can also see the
  *titles* of sections still being prepared, which the admin can switch off.
- **Proposal sections** embed the published proposal (`portal._proposal` with `$embedded`).
  **Pricing** is shown only to members with `can_see_pricing`. That covers the proposal's pricing
  and any section marked `pricing_only`.
- **Access** (`microsite_members`): each member has a role, `viewer` (reads) or `contributor`
  (can also comment on sections). A member's status is `invited`, `requested` (waiting for an
  admin) or `declined`. Sign-in links are hashed, reusable and last 14 days, like `PortalInvite`,
  and the session key is per account.
- **Feedback** (`microsite_comments`): contributors comment on a section and see only their
  own comments. The admin replies from the builder, and the reply is emailed to them.
- The logo must be PNG, JPG or WebP (no SVG), stored on the local disk and streamed by route.
  The colour is validated as `#rrggbb`, and the ink colour is picked for contrast
  (`brandInk`).

## The learning loop

`Prompts::lessons()` adds a "WHAT WE HAVE LEARNED" block to discovery replies, plan drafts,
proposal drafts and microsite sections. It holds the last 12 won or lost outcome notes for the
offering, and the last 15 questions prospects asked in their portals. New system rule 9: never
name or hint at another company in anything a prospect reads. Only the industry travels with a
lesson, not the company name. The outcome-note field on a prospect now says this.

## Not built yet

- Partners or Product Partners building microsites (A2, later).
- The per-prospect portal (`/proposal/{ref}`) and the microsite are still separate sign-ins.
  Folding the portal's forms (details, Q&A, quote requests) into the microsite is the natural
  next step.
- A portal-style AI assistant on the microsite (D1: it would need the API key on live).
- Account-level reporting (pipeline by stage, time in stage).
