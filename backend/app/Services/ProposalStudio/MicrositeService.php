<?php

namespace App\Services\ProposalStudio;

use App\Mail\MicrositeActivity;
use App\Mail\MicrositeInvitation;
use App\Mail\MicrositeReply;
use App\Models\BusinessAccount;
use App\Models\MicrositeComment;
use App\Models\MicrositeMember;
use App\Models\MicrositeSection;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\StudioJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A business account's microsite: its sections, which the assistant drafts
 * and an admin publishes one by one, and who may open it. Owner, 2026-10-28:
 * we invite people, and anyone invited may ask for a colleague, whom an
 * admin approves before a link is sent. See memory-bank/b2b-accounts.md.
 */
class MicrositeService
{
    public function __construct(private JobQueue $queue) {}

    // ── Sections ─────────────────────────────────────────────────────────

    /** The usual outline, linked to the account's latest opportunity. Adds nothing if there are sections already. */
    public function starterSections(BusinessAccount $account): void
    {
        if ($account->sections()->exists()) {
            return;
        }
        $opportunity = $account->opportunities()->first();
        $kinds = ['welcome', 'challenges', 'solution', 'evidence'];
        if ($opportunity?->publishedProposal) {
            $kinds[] = 'proposal';
        }
        array_push($kinds, 'faq', 'next_steps');

        foreach ($kinds as $kind) {
            $this->addSection($account, $kind, $opportunity);
        }
    }

    public function addSection(BusinessAccount $account, string $kind, ?Prospect $opportunity): MicrositeSection
    {
        if (! isset(MicrositeSection::KINDS[$kind])) {
            throw new StudioRefused('Unknown kind of section.');
        }
        if ($opportunity !== null && $opportunity->business_account_id !== $account->id) {
            throw new StudioRefused('That opportunity belongs to another account.');
        }
        [, $title, $brief] = MicrositeSection::KINDS[$kind];

        return $account->sections()->create([
            'kind' => $kind,
            'title' => $title,
            'brief' => $brief ?: null,
            'prospect_id' => $opportunity?->id,
            'position' => (int) $account->sections()->max('position') + 1,
            'status' => 'draft',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function updateSection(MicrositeSection $section, array $data): void
    {
        if (array_key_exists('prospect_id', $data) && $data['prospect_id'] !== null
            && ! Prospect::where('id', $data['prospect_id'])->where('business_account_id', $section->business_account_id)->exists()) {
            throw new StudioRefused('That opportunity belongs to another account.');
        }
        $section->fill($data);
        if (array_key_exists('claim_ids', $data)) {
            $section->setRelation('opportunity', $section->prospect_id ? Prospect::with('offering')->find($section->prospect_id) : null);
            $section->claim_ids = Jobs\MicrositeSectionDraft::cleanClaimIds($data['claim_ids'], $section);
        }
        $section->save();
    }

    public function draft(MicrositeSection $section, User $by): void
    {
        if (! $section->isWritten()) {
            throw new StudioRefused('This section shows material from the library as it is. There is nothing for the assistant to write.');
        }
        if (in_array($section->kind, ['solution', 'evidence', 'faq'], true) && $section->prospect_id === null) {
            throw new StudioRefused('Link this section to an opportunity first, so the assistant knows which product to draw on.');
        }
        if ($section->isAssistantBusy()) {
            throw new StudioRefused('The assistant is already writing this section.');
        }
        $this->guardDraftRate($by);
        $this->queue->dispatch(JobQueue::MICROSITE_SECTION, $section, $by);
    }

    /** @return int how many sections were sent to the assistant */
    public function draftEmpty(BusinessAccount $account, User $by): int
    {
        $sent = 0;
        foreach ($account->sections()->whereNull('body')->get() as $section) {
            try {
                $this->draft($section, $by);
                $sent++;
            } catch (StudioRefused) {
                // Not one the assistant writes, or not linked yet: skip it.
            }
        }

        return $sent;
    }

    public function publish(MicrositeSection $section, User $by): void
    {
        if ($section->isWritten() && blank($section->body)) {
            throw new StudioRefused('Write or draft the section before publishing it.');
        }
        if ($section->kind === 'proposal' && $section->opportunity?->publishedProposal === null) {
            throw new StudioRefused('The linked opportunity has no published proposal to show.');
        }
        $section->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $section->account->ensureSiteRef();
        $section->account->log('system', "Published the \"{$section->title}\" section of the microsite.", $by, ['section' => $section->id]);
    }

    public function unpublish(MicrositeSection $section, User $by): void
    {
        $section->forceFill(['status' => 'draft'])->save();
        $section->account->log('system', "Took the \"{$section->title}\" section off the microsite.", $by, ['section' => $section->id]);
    }

    public function move(MicrositeSection $section, int $direction): void
    {
        DB::transaction(function () use ($section, $direction) {
            $sections = $section->account->sections()->get();
            $order = $sections->all();
            $from = $sections->search(fn ($s) => $s->id === $section->id);
            $to = $from + ($direction < 0 ? -1 : 1);
            if (! isset($order[$to])) {
                return;
            }
            [$order[$from], $order[$to]] = [$order[$to], $order[$from]];
            foreach ($order as $position => $s) {
                $s->forceFill(['position' => $position + 1])->save();
            }
        });
    }

    // ── Who may open it ──────────────────────────────────────────────────

    public function invite(BusinessAccount $account, string $email, ?string $name, string $role, bool $pricing, User $by): MicrositeMember
    {
        if (! $account->sections()->where('status', 'published')->exists()) {
            throw new StudioRefused('Publish at least one section first, so there is something to open.');
        }

        $member = MicrositeMember::firstOrNew(['business_account_id' => $account->id, 'email' => Str::lower(trim($email))]);
        $member->fill([
            'name' => $name ?: $member->name,
            'role' => $role,
            'can_see_pricing' => $pricing,
            'status' => 'invited',
            'created_by' => $member->created_by ?? $by->id,
        ]);
        $member->revoked_at = null;
        $member->save();

        $this->sendLink($member);
        $account->log('system', "Invited {$member->label()} to the microsite as a ".strtolower(MicrositeMember::ROLES[$role]).($pricing ? ', with pricing' : '').'.', $by, ['member' => $member->id]);

        return $member;
    }

    public function sendLink(MicrositeMember $member): void
    {
        $member->account->ensureSiteRef();
        $token = $member->issueToken();
        Mail::to($member->email)->send(new MicrositeInvitation($member, $token));
    }

    public function updateAccess(MicrositeMember $member, string $role, bool $pricing, User $by): void
    {
        $member->forceFill(['role' => $role, 'can_see_pricing' => $pricing])->save();
        $member->account->log('system', "Changed {$member->label()}'s access: ".strtolower(MicrositeMember::ROLES[$role]).($pricing ? ', with pricing' : ', no pricing').'.', $by, ['member' => $member->id]);
    }

    public function revoke(MicrositeMember $member, User $by): void
    {
        $member->forceFill(['revoked_at' => now(), 'token_hash' => null])->save();
        $member->account->log('system', "Removed {$member->label()}'s access to the microsite.", $by, ['member' => $member->id]);
    }

    /** A member asks us to add a colleague. Nothing is sent until an admin approves. */
    public function requestAccess(MicrositeMember $by, string $email, ?string $name, ?string $note): MicrositeMember
    {
        $email = Str::lower(trim($email));
        $account = $by->account;

        $recent = MicrositeMember::where('requested_by_member_id', $by->id)->where('created_at', '>=', now()->subDay())->count();
        if ($recent >= (int) config('proposal-studio.microsite.daily_requests')) {
            throw new StudioRefused('You have asked for several colleagues today. Please try again tomorrow, or reply to our email.');
        }

        $existing = MicrositeMember::where('business_account_id', $account->id)->where('email', $email)->first();
        if ($existing !== null && ($existing->isActive() || $existing->isPendingRequest())) {
            throw new StudioRefused($existing->isActive() ? 'That person already has access. They can ask for a new link on the sign-in page.' : 'We already have a request for that person.');
        }

        $member = $existing ?? new MicrositeMember(['business_account_id' => $account->id, 'email' => $email]);
        $member->fill([
            'name' => $name,
            'role' => 'viewer',
            'can_see_pricing' => false,
            'status' => 'requested',
            'requested_by_member_id' => $by->id,
            'request_note' => $note,
        ]);
        $member->revoked_at = null;
        $member->save();

        $account->log('client', "{$by->label()} asked for {$member->label()} to be given access to the microsite.", null, ['member' => $member->id, 'by_member' => $by->id]);
        $this->notifyAdmins($account, "{$account->name}: access requested for a colleague", [
            "{$by->label()} asked us to give {$member->label()} access to the microsite.",
            $note ? "Their note: {$note}" : null,
            'Nothing has been sent. Approve or decline the request on the account page.',
        ]);

        return $member;
    }

    public function approveRequest(MicrositeMember $member, string $role, bool $pricing, User $by): void
    {
        if (! $member->isPendingRequest()) {
            throw new StudioRefused('This request has already been dealt with.');
        }
        $member->forceFill(['status' => 'invited', 'role' => $role, 'can_see_pricing' => $pricing, 'created_by' => $by->id])->save();
        $this->sendLink($member);
        $member->account->log('system', "Approved access for {$member->label()} and sent them a link.", $by, ['member' => $member->id]);
    }

    public function declineRequest(MicrositeMember $member, User $by): void
    {
        if (! $member->isPendingRequest()) {
            throw new StudioRefused('This request has already been dealt with.');
        }
        $member->forceFill(['status' => 'declined'])->save();
        $member->account->log('system', "Declined access for {$member->label()}.", $by, ['member' => $member->id]);
    }

    /** A new link for someone who lost theirs. Says nothing either way. */
    public function requestLink(BusinessAccount $account, string $email): void
    {
        $member = MicrositeMember::where('business_account_id', $account->id)->where('email', Str::lower(trim($email)))->first();
        if ($member !== null && $member->isActive() && ($member->sent_at === null || $member->sent_at->lt(now()->subMinutes(2)))) {
            $this->sendLink($member);
        }
    }

    public function signIn(Request $request, BusinessAccount $account, string $token): ?MicrositeMember
    {
        $member = MicrositeMember::findByToken($account, $token);
        if ($member === null) {
            return null;
        }
        $request->session()->put(self::sessionKey($account), $member->id);
        $member->forceFill(['last_seen_at' => now()])->save();
        $account->log('client', "{$member->label()} signed in to the microsite.", null, ['member' => $member->id]);

        return $member;
    }

    public function current(Request $request, BusinessAccount $account): ?MicrositeMember
    {
        $id = $request->session()->get(self::sessionKey($account));
        $member = $id ? MicrositeMember::where('business_account_id', $account->id)->find($id) : null;

        return $member !== null && $member->isActive() ? $member : null;
    }

    public static function sessionKey(BusinessAccount $account): string
    {
        return 'microsite_access.'.$account->id;
    }

    /** A visit, on the timeline at most once every few hours per person. */
    public function recordVisit(MicrositeMember $member): void
    {
        $seen = $member->last_seen_at;
        $member->forceFill(['last_seen_at' => now()])->save();
        if ($seen === null || $seen->lt(now()->subHours(4))) {
            $member->account->log('client', "{$member->label()} opened the microsite.", null, ['member' => $member->id]);
        }
    }

    // ── Feedback ─────────────────────────────────────────────────────────

    public function comment(MicrositeSection $section, MicrositeMember $member, string $body): MicrositeComment
    {
        if (! $member->canComment() || ! $section->isPublished() || $section->business_account_id !== $member->business_account_id) {
            throw new StudioRefused('You cannot leave feedback here.');
        }
        $recent = MicrositeComment::where('microsite_member_id', $member->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= (int) config('proposal-studio.microsite.hourly_comments')) {
            throw new StudioRefused('You have sent a lot in the last hour. Please try again a little later, or reply to our email.');
        }

        $comment = $section->comments()->create(['microsite_member_id' => $member->id, 'body' => $body]);
        $account = $section->account;
        $account->log('client', "{$member->label()} commented on \"{$section->title}\": ".Str::limit($body, 300), null, ['member' => $member->id, 'comment' => $comment->id]);
        $this->notifyAdmins($account, "{$account->name} left feedback on their microsite", [
            "{$member->label()} commented on \"{$section->title}\":",
            "> {$body}",
        ]);

        return $comment;
    }

    public function reply(MicrositeComment $comment, string $reply, User $by): void
    {
        $comment->forceFill(['reply' => $reply, 'replied_by' => $by->id, 'replied_at' => now()])->save();
        $comment->section->account->log('system', "Replied to {$comment->member?->label()}'s comment on \"{$comment->section->title}\".", $by, ['comment' => $comment->id]);
        if ($comment->member?->isActive()) {
            Mail::to($comment->member->email)->send(new MicrositeReply($comment));
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @param  list<?string>  $lines */
    private function notifyAdmins(BusinessAccount $account, string $subject, array $lines): void
    {
        $to = array_values(array_filter([$account->owner?->email]))
            ?: User::whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->pluck('email')->all();
        if ($to) {
            Mail::to($to)->send(new MicrositeActivity($account, $subject, array_values(array_filter($lines))));
        }
    }

    private function guardDraftRate(User $by): void
    {
        $limit = (int) config('proposal-studio.microsite.hourly_drafts');
        $sent = StudioJob::where('created_by', $by->id)->where('kind', JobQueue::MICROSITE_SECTION)
            ->where('created_at', '>=', now()->subHour())->count();
        if ($sent >= $limit) {
            throw new StudioRefused("You have sent {$limit} sections to the assistant in the last hour, which is the limit. Please try again a little later.");
        }
    }
}
