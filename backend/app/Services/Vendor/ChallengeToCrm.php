<?php

namespace App\Services\Vendor;

use App\Models\CrmContact;
use App\Models\CrmContactActivity;
use App\Models\CrmFollowup;
use App\Models\CrmNote;
use App\Models\CrmTag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts someone who takes a product challenge into the sharing partner's CRM.
 *
 * The challenge page is public and unauthenticated, so everything here is
 * written on the partner's behalf: the partner owns the contact (it came
 * through their link), and the admin CRM sees it with everyone else's.
 *
 * Two rules keep anonymous input from doing damage:
 *   - an existing contact is never renamed or re-statused — a visitor typing
 *     a customer's email must not overwrite what the partner knows about
 *     them. Only the challenge snapshot, the tag and a note are added;
 *   - one pending follow-up per contact, however many times they play.
 *
 * Same shape as ProspectToCrm: one-directional, detail in custom_data.
 */
class ChallengeToCrm
{
    public const TAG = 'PlasmaGuard Challenge';

    /** The CRM's own lead_source enum: the challenge is a web page. */
    public const SOURCE = 'website';

    public function enter(User $owner, string $key, string $productName, string $firstName, string $email): CrmContact
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($owner, $key, $productName, $firstName, $email) {
            $contact = CrmContact::firstOrNew(['owner_id' => $owner->id, 'email' => $email]);
            $isNew = ! $contact->exists;

            if ($isNew) {
                $contact->fill([
                    'first_name'          => $firstName,
                    'last_name'           => '',
                    'contact_type'        => 'prospect',
                    'status'              => 'new',
                    'lead_source'         => self::SOURCE,
                    'created_by'          => $owner->id,
                    'assigned_to'         => $owner->id,
                    'referred_by_user_id' => $owner->id,
                ]);
            }

            $data = $contact->custom_data ?? [];
            $entry = $data['challenge'][$key] ?? [];
            $data['challenge'][$key] = array_merge([
                'product'    => $productName,
                'entered_at' => now()->toIso8601String(),
                'plays'      => 0,
            ], $entry, [
                'name_given' => $firstName,
                'last_seen'  => now()->toIso8601String(),
            ]);
            $contact->custom_data = $data;
            $contact->save();

            $tag = CrmTag::firstOrCreate(
                ['user_id' => null, 'name' => self::TAG],
                ['color' => '#C69B3C'],
            );
            $contact->tags()->syncWithoutDetaching([$tag->id]);

            $this->log($contact, 'challenge_entered', $isNew
                ? "{$firstName} took the {$productName} challenge from {$owner->name}'s link"
                : "Existing contact took the {$productName} challenge (as \"{$firstName}\")");

            $this->followUp($contact, $owner, $productName);

            return $contact;
        });
    }

    /**
     * A finished round. The figures come from the visitor's browser, so they
     * are a talking point for the follow-up call, not data to rely on.
     */
    public function result(CrmContact $contact, string $key, array $r): void
    {
        DB::transaction(function () use ($contact, $key, $r) {
            $data = $contact->custom_data ?? [];
            $entry = $data['challenge'][$key] ?? [];
            $entry['plays'] = ($entry['plays'] ?? 0) + 1;
            $entry['last'] = $r + ['at' => now()->toIso8601String()];
            $entry['best_left'] = min($entry['best_left'] ?? PHP_INT_MAX, $r['you_left']);
            $data['challenge'][$key] = $entry;
            $contact->custom_data = $data;
            $contact->save();

            $won = $r['you_left'] <= $r['pro_left'];
            $product = $entry['product'] ?? 'the product';

            CrmNote::create([
                'contact_id'  => $contact->id,
                'user_id'     => $contact->owner_id,
                'type'        => 'other',
                'title'       => "Challenge round {$entry['plays']}: ".($won ? 'kept up with' : 'lost to')." the PRO",
                'body'        => sprintf(
                    "Wiped %d germs by hand and left %d behind. The %s cleared %d and left %d.\n\n"
                    ."Recorded automatically from the challenge page; figures are as reported by the visitor's browser.",
                    intdiv($r['you'], 10), $r['you_left'], $product, intdiv($r['pro'], 10), $r['pro_left'],
                ),
                'occurred_at' => now(),
            ]);

            $this->log($contact, 'challenge_played', "Played challenge round {$entry['plays']}");
        });
    }

    /**
     * Before an enquiry is filed: carry the email the challenger gives when
     * ordering onto the contact their challenge created.
     *
     * Their enquiry is the identity they chose to give once ready to buy, so
     * it wins over what they typed to play — people put a throwaway email into
     * a game on purpose. It has to happen BEFORE the enquiry is captured: the
     * enquiry files itself into the CRM by email (VendorReferralService), so
     * with the address corrected first it lands on this same record — one
     * contact, carrying the challenge history, the enquiry and, later, the
     * purchase — instead of a second contact alongside.
     *
     * Only a contact this visitor's own entry CREATED is touched ($mayRewrite).
     * If their entry matched a contact the partner already had, the enquiry
     * says nothing reliable about that person.
     *
     * @return list<string> lines for the note ordered() writes
     */
    public function claimEmail(CrmContact $contact, bool $mayRewrite, string $email): array
    {
        $email = mb_strtolower(trim($email));

        if (! $mayRewrite) {
            return ['This contact existed before the challenge, so its details were left as they were.'];
        }

        if ($email === mb_strtolower((string) $contact->email)) {
            return [];
        }

        $other = CrmContact::where('owner_id', $contact->owner_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereKeyNot($contact->id)
            ->first();

        if ($other) {
            // Two records for one person. Merging is the partner's call, so
            // point each at the other; the enquiry files onto $other.
            $this->noteOn($other, 'Also took the challenge',
                "Played the challenge as {$contact->email} (contact #{$contact->id}), then ordered with this email.");

            return ["Ordered with {$email}, which is already contact #{$other->id} ({$other->full_name}) — "
                .'the enquiry is filed there. Likely the same person; merge by hand if so.'];
        }

        $old = $contact->email;
        $contact->forceFill(['email' => $email])->save();
        $this->log($contact, 'email_corrected', "Email corrected from {$old} to {$email} when ordering");

        return ["Email corrected from {$old} to {$email}."];
    }

    /** After the enquiry is filed: the note, the status, the snapshot. */
    public function ordered(CrmContact $contact, bool $mayRewrite, string $key, array $buyer, string $leadRef, array $lines): void
    {
        DB::transaction(function () use ($contact, $mayRewrite, $key, $buyer, $leadRef, $lines) {
            $contact->refresh();
            $name = trim(($buyer['first_name'] ?? '').' '.($buyer['last_name'] ?? ''));
            $email = mb_strtolower(trim($buyer['email']));
            $product = $contact->custom_data['challenge'][$key]['product'] ?? 'the product';

            if ($mayRewrite && $contact->status === 'new') {
                $contact->status = 'interested';
            }

            $data = $contact->custom_data ?? [];
            $data['challenge'][$key]['ordered'] = [
                'lead' => $leadRef, 'email' => $email, 'at' => now()->toIso8601String(),
            ];
            $contact->custom_data = $data;
            $contact->save();

            array_unshift($lines, "Went on to order {$product}: enquiry {$leadRef}, as {$name} <{$email}>.");
            $this->noteOn($contact, 'Moved on to ordering', implode("\n\n", $lines));
            $this->log($contact, 'challenge_ordered', "Moved from the challenge to ordering (enquiry {$leadRef})");
        });
    }

    private function noteOn(CrmContact $contact, string $title, string $body): void
    {
        CrmNote::create([
            'contact_id'  => $contact->id,
            'user_id'     => $contact->owner_id,
            'type'        => 'other',
            'title'       => $title,
            'body'        => $body,
            'occurred_at' => now(),
        ]);
    }

    private function followUp(CrmContact $contact, User $owner, string $productName): void
    {
        $title = "Follow up: took the {$productName} challenge";

        $open = CrmFollowup::where('contact_id', $contact->id)
            ->where('title', $title)
            ->whereIn('status', ['pending', 'overdue', 'snoozed'])
            ->exists();

        if ($open) {
            return;
        }

        $followup = CrmFollowup::create([
            'contact_id'  => $contact->id,
            'assigned_to' => $owner->id,
            'created_by'  => $owner->id,
            'title'       => $title,
            'description' => 'They played the "Can you keep up?" game from your product link. Ask how it went, '
                .'and whether they would like the PRO for their home or building.',
            'type'        => 'email',
            'priority'    => 'medium',
            'status'      => 'pending',
            'due_at'      => now()->addDay(),
        ]);

        $contact->update(['next_followup_at' => $followup->due_at]);
    }

    private function log(CrmContact $contact, string $type, string $description): void
    {
        // No user: nobody signed in did this. The CRM timeline shows it as
        // the system's entry.
        CrmContactActivity::create([
            'contact_id'  => $contact->id,
            'user_id'     => null,
            'type'        => $type,
            'description' => $description,
        ]);
    }
}
