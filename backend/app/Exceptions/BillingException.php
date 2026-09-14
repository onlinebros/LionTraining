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

    public static function cardRequired(): self
    {
        return new self(
            'Membership needs a credit or debit card. Bank accounts and Link cannot be used — no charge has been made.'
        );
    }

    public static function walletNotAccepted(): self
    {
        return new self(
            'Apple Pay and Google Pay cannot be used for membership. Please enter your card details instead — no charge has been made.'
        );
    }

    public static function cardNotVerifiable(): self
    {
        return new self(
            'We could not verify that card. Please try a different card — no charge has been made.'
        );
    }

    public static function connectDisabled(): self
    {
        return new self(
            'Payout accounts are not available right now. Please check back soon.'
        );
    }

    public static function duplicateConnectIdentity(): self
    {
        return new self(
            'That bank account is already set up for payouts on another account. Please use a different bank account, or contact support.'
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
