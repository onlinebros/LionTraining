<?php

namespace App\Services\Partner;

use RuntimeException;

/**
 * Raised when a position gains an owner between verification and submission.
 *
 * Rare, and worth its own type rather than a silent no-op: the alternative is
 * writing the second person's name and password over the first person's
 * account, which is the one outcome this whole flow exists to prevent.
 */
class SpotAlreadyClaimed extends RuntimeException
{
    public function __construct(string $message = 'This position has already been claimed.')
    {
        parent::__construct($message);
    }
}
