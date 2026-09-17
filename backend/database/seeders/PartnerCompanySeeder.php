<?php

namespace Database\Seeders;

use App\Models\PartnerCompany;
use Illuminate\Database\Seeder;

/**
 * The partner companies whose branding lives in this repo.
 *
 * A seeder rather than a hand-typed admin row because the claim page is a
 * public, co-branded URL: the slug is printed in the partner's own mailings,
 * the logo is a committed asset, and the wording was agreed with them. All
 * three should arrive with a deploy and be reviewable in a diff, not depend on
 * somebody retyping them correctly on the production box.
 *
 * Idempotent, and deliberately conservative about what it overwrites. It sets
 * the things that come from the agreement — name, branding, field labels — and
 * never touches `is_active` or the webhook settings, because those are
 * operational switches somebody threw on purpose and a deploy must not throw
 * them back.
 */
class PartnerCompanySeeder extends Seeder
{
    public function run(): void
    {
        PartnerCompany::updateOrCreate(
            ['slug' => 'ihub'],
            [
                'name' => 'iHub Global',

                // Committed asset, not an upload: see PartnerCompany::logoUrl().
                // The claim page renders on the dark theme, which is the mark
                // they supplied.
                'logo_dark_path' => 'assets/images/partners/ihub/ihub-dark.png',

                'headline' => 'Your iHub Global position is waiting',

                'intro' => 'As part of the partnership between iHub Global and Quantum 3 Solution, '
                    . 'a position has been reserved for you — in the same place, with the same people '
                    . 'below you, as you have at iHub. Enter your iHub details below to claim it.',

                // iHub's people know these by iHub's names for them. A page
                // asking for a "User ID" when their paperwork says something
                // else is a support ticket per person.
                'identifier_label' => 'iHub User ID',
                'activation_label' => 'Activation Code',
            ],
        );

        // The endpoint and signature style iHub gave us. Both belong in the
        // repo rather than being typed into the admin: they are part of the
        // integration contract, and a mistyped URL fails silently into a
        // retry queue rather than loudly on screen.
        //
        // The secret is NOT here and must never be — it is set once through the
        // admin form, stored encrypted, and the switch is thrown by a human who
        // has confirmed with iHub that their receiver is ready. Seeding a
        // webhook on would start sending live claims at an endpoint nobody had
        // agreed was listening.
        PartnerCompany::where('slug', 'ihub')
            ->whereNull('webhook_url')
            ->update([
                'webhook_url'             => 'https://app.ihub.global/api/_webhook/partner/quantum3',
                'webhook_signature_style' => PartnerCompany::SIGNATURE_SHA256,
            ]);
    }
}
