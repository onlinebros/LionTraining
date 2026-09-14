<?php

declare(strict_types=1);

namespace Tests\StaticGate\Fixtures;

use App\Events\Taxonomy\AffiliateLinkClicked;
use App\Events\Taxonomy\UpgradeCompleted;

// This fixture is intentionally broken. It MUST NOT be added to phpstan.neon's
// `paths`. tests/Unit/EventTaxonomyStaticGateTest.php runs PHPStan against this
// file out-of-band and asserts the analysis reports an error per missing
// required field, proving the codegen'd ctor signature is a real type gate.

final class MissingRequiredField
{
    public function missingPlacementId(): UpgradeCompleted
    {
        // @phpstan-expected: missing placement_id
        return new UpgradeCompleted(
            user_id: 1,
            plan_id: 1,
            amount_cents: 19700,
            currency: 'USD',
        );
    }

    public function missingAffiliateIdOnClick(): AffiliateLinkClicked
    {
        // @phpstan-expected: missing affiliate_id
        return new AffiliateLinkClicked(
            share_token: 'tok',
            placement_id: 'fixture',
            session_id: 'sess',
        );
    }
}
