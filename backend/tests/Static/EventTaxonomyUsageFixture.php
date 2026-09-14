<?php

declare(strict_types=1);

namespace Tests\Static;

use App\Events\Taxonomy\AffiliateLinkClicked;
use App\Events\Taxonomy\UpgradeCompleted;

/**
 * Compile-time witness for the event-taxonomy type gate. This file is included
 * in the production PHPStan analysis path. If a future taxonomy change removes
 * one of the required fields below — or this file omits one — PHPStan fails
 * the CI gate. Conversely, the matching tests/StaticGate/Fixtures/*.php files
 * are NOT in the production analysis path; an out-of-band PHPStan run against
 * them asserts that omitting a required field reports an error.
 */
final class EventTaxonomyUsageFixture
{
    public function upgradeWithAffiliateAttribution(): UpgradeCompleted
    {
        return new UpgradeCompleted(
            user_id: 1,
            plan_id: 1,
            amount_cents: 19700,
            currency: 'USD',
            placement_id: 'fixture',
            affiliate_id: 2,
        );
    }

    public function upgradeOrganic(): UpgradeCompleted
    {
        return new UpgradeCompleted(
            user_id: 1,
            plan_id: 1,
            amount_cents: 19700,
            currency: 'USD',
            placement_id: 'fixture',
        );
    }

    public function affiliateClick(): AffiliateLinkClicked
    {
        return new AffiliateLinkClicked(
            affiliate_id: 2,
            share_token: 'tok',
            placement_id: 'fixture',
            session_id: 'sess',
        );
    }
}
