<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A billing failure with a message safe to show a partner.
 *
 * Every message says plainly whether money moved. "No charge has been made" is
 * the sentence that stops a failed signup turning into a support ticket, and
 * every constructor here that can fire before a charge includes it.
 */
class BillingException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'Payments are not set up yet. Please contact support — no charge has been made.'
        );
    }

    public static function noPriceConfigured(): self
    {
        return new self(
            'No membership price is configured. Please contact support — no charge has been made.'
        );
    }

    public static function duplicateCard(): self
    {
        return new self(
            'That card is already on another account. Please use a different card — no charge has been made.'
        );
    }

    public static function lastCardOnActiveSubscription(): self
    {
        return new self(
            'This is the only card on your active membership. Add a replacement before removing it.'
        );
    }

    public static function cannotResumeEnded(): self
    {
        return new self(
            'This membership has already ended and cannot be resumed. Start a new one instead.'
        );
    }
}
