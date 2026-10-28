<?php

namespace App\Services\ProposalStudio;

use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proposal versions: drafting, editing, the vendor's review (owner decision
 * D5) and publishing to the prospect's portal.
 */
class ProposalService
{
    public function __construct(private JobQueue $queue, private StudioService $studio) {}

    /** Ask the assistant to draft the proposal page: into the open draft, or a new version. */
    public function draft(Prospect $prospect, User $by): Proposal
    {
        $this->studio->guardTurn($by, $prospect);

        return DB::transaction(function () use ($prospect, $by) {
            $proposal = $this->openDraft($prospect) ?? Proposal::create([
                'prospect_id' => $prospect->id,
                'version' => $this->nextVersion($prospect),
                'status' => 'draft',
                'created_by' => $by->id,
            ]);
            $this->queue->dispatch(JobQueue::PROPOSAL_DRAFT, $proposal, $by);

            return $proposal;
        });
    }

    /** A new draft version to edit, copied from the one prospects see now. */
    public function newVersion(Proposal $from, User $by): Proposal
    {
        if ($open = $this->openDraft($from->prospect)) {
            return $open;
        }

        return Proposal::create([
            'prospect_id' => $from->prospect_id,
            'version' => $this->nextVersion($from->prospect),
            'status' => 'draft',
            'content' => $from->content,
            'created_by' => $by->id,
        ]);
    }

    /** @param  array<string, mixed>  $content */
    public function update(Proposal $proposal, array $content): void
    {
        if ($proposal->status !== 'draft') {
            throw new StudioRefused('Only a draft can be edited. Start a new version to change what the prospect sees.');
        }

        $proposal->forceFill(['content' => Jobs\ProposalDraft::clean($content, $proposal)])->save();
    }

    /** @return string what to tell the admin */
    public function publish(Proposal $proposal, User $by): string
    {
        if ($proposal->status !== 'draft' || empty($proposal->content)) {
            throw new StudioRefused('Only a finished draft can be published.');
        }

        if ($this->needsVendorReview($proposal)) {
            $proposal->forceFill([
                'status' => 'in_review',
                'vendor_review' => 'pending',
                'vendor_review_note' => null,
                'published_by' => $by->id,
            ])->save();

            return 'Sent to '.$proposal->prospect->offering->vendor_name.' for review. It is published to the prospect as soon as they approve it.';
        }

        $this->makeLive($proposal, $by);

        return "Version {$proposal->version} is published. Invite the prospect to see it.";
    }

    /** The vendor's answer, from the Product Partner portal. */
    public function vendorReview(Proposal $proposal, User $vendorUser, bool $approve, ?string $note): void
    {
        if ($proposal->status !== 'in_review') {
            throw new StudioRefused('This proposal is not waiting for review.');
        }

        $proposal->forceFill([
            'vendor_review' => $approve ? 'approved' : 'changes_requested',
            'vendor_review_note' => $note,
            'vendor_reviewed_by' => $vendorUser->id,
            'vendor_reviewed_at' => now(),
        ]);

        if ($approve) {
            $this->makeLive($proposal, $proposal->publisher ?? $vendorUser);
        } else {
            $proposal->forceFill(['status' => 'draft'])->save();
        }
    }

    /**
     * While an offering is in its review period, every proposal for it goes
     * past the vendor first: the first N they approve end the period.
     */
    public function needsVendorReview(Proposal $proposal): bool
    {
        $offering = $proposal->prospect->offering;
        if (! $offering->vendor_key) {
            return false;
        }

        $approved = Proposal::where('vendor_review', 'approved')
            ->whereHas('prospect', fn ($q) => $q->where('offering_id', $offering->id))
            ->count();

        return $approved < (int) config('proposal-studio.vendor_review_first');
    }

    private function makeLive(Proposal $proposal, User $by): void
    {
        DB::transaction(function () use ($proposal, $by) {
            Proposal::where('prospect_id', $proposal->prospect_id)->where('status', 'published')
                ->update(['status' => 'superseded']);
            $proposal->forceFill(['status' => 'published', 'published_by' => $by->id, 'published_at' => now()])->save();

            $prospect = $proposal->prospect;
            $prospect->ensurePortalRef();
            if ($prospect->status === 'discovery') {
                $prospect->forceFill(['status' => 'proposal'])->save();
            }
        });
    }

    private function openDraft(Prospect $prospect): ?Proposal
    {
        return $prospect->proposals()->where('status', 'draft')->first();
    }

    private function nextVersion(Prospect $prospect): int
    {
        return (int) Proposal::where('prospect_id', $prospect->id)->max('version') + 1;
    }
}
