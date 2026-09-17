<?php

namespace App\Services\Genealogy;

use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place an enrollment is written.
 *
 * Two representations of "who sponsored whom" exist in this codebase:
 *
 *   users.sponsor_id + enrollment_path   the genealogy — authoritative
 *   sponsorships rows                    read by ~15 admin and member screens
 *
 * They must never disagree, so nothing writes either one directly. Both are
 * written here, in a single transaction, and every caller goes through
 * enroll(). The sponsorships table is legacy: once those screens are moved onto
 * the genealogy relations it can be dropped, and this class loses a few lines.
 *
 * Sponsorship is recorded as ACTIVE, not pending. During pre-launch a partner
 * must be in the tree the moment they register — a sponsorship awaiting the
 * sponsor's approval would leave them outside the structure during exactly the
 * window the phase exists to fill. See docs/quantum-life-solutions/09-prelaunch.md
 * § 4 in the reference repo: "Sponsorship relationships — real and permanent."
 */
class EnrollmentService
{
    public function __construct(private GenealogyService $genealogy) {}

    /**
     * Enroll $user beneath $sponsor and place them in the structure.
     *
     * Placement is synchronous rather than queued: position must be real and
     * visible from the moment someone signs up, not applied in a batch later.
     * `network:place-queued` exists as the safety net for the cases this path
     * cannot cover.
     *
     * @param  string|null  $notes  Free text carried onto the legacy row.
     */
    public function enroll(User $user, ?User $sponsor, ?string $notes = null): User
    {
        return DB::transaction(function () use ($user, $sponsor, $notes) {
            $sponsor ??= $this->defaultSponsor();

            $this->genealogy->enroll($user, $sponsor);

            if ($sponsor !== null) {
                Sponsorship::updateOrCreate(
                    ['sponsor_id' => $sponsor->id, 'sponsored_id' => $user->id],
                    ['status' => 'active', 'notes' => $notes, 'accepted_at' => Carbon::now()],
                );
            }

            return $this->genealogy->place($user->refresh());
        });
    }

    /**
     * Enroll a partner at a position that came from somewhere else.
     *
     * Used by the partner-spot importer. It differs from enroll() in one way
     * that matters: position is taken from the file, not computed by the
     * placement strategy. A partner company's list already describes a
     * structure, and running it back through our strategy would reshape their
     * organisation on the way in — legs that were side by side there would come
     * out stacked here.
     *
     * $sponsor defaults to the placement parent, which is what a unilevel means
     * anyway. It is a separate argument because a partner company that tracks
     * recruitment apart from position hands us both, and the enrollment tree is
     * the one that stays true forever.
     */
    public function enrollImported(User $user, User $placementParent, ?User $sponsor = null): User
    {
        return DB::transaction(function () use ($user, $placementParent, $sponsor) {
            $sponsor ??= $placementParent;

            $this->genealogy->enroll($user, $sponsor);

            Sponsorship::updateOrCreate(
                ['sponsor_id' => $sponsor->id, 'sponsored_id' => $user->id],
                [
                    'status'      => 'active',
                    'notes'       => 'Imported from ' . ($user->partnerCompany?->name ?? 'a partner company') . '.',
                    'accepted_at' => Carbon::now(),
                ],
            );

            return $this->genealogy->placeUnder($user->refresh(), $placementParent);
        });
    }

    /**
     * The house account unreferred signups are placed under, if configured.
     *
     * Without one they become roots of their own tree, which is the right
     * default for the founding team but usually not for public signups.
     */
    private function defaultSponsor(): ?User
    {
        $id = config('genealogy.default_sponsor_id');

        return $id === null ? null : User::find($id);
    }
}
