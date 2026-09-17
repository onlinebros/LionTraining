<?php

namespace App\Services\Partner;

use Illuminate\Support\Facades\Config;

/**
 * How an activation code is stored and checked.
 *
 * Keyed HMAC-SHA256, not bcrypt. Two reasons, and the first is the one that
 * matters:
 *
 * 1. **These are tokens, not passwords.** A partner's codes are randomly
 *    generated — iHub's are 12 characters from a 36-character alphabet, about
 *    62 bits. bcrypt exists to make *guessing* expensive for secrets drawn from
 *    a space small enough to enumerate: a human-chosen password lives in maybe
 *    2^30 realistic candidates, so you need each guess to cost milliseconds.
 *    Enumerating 2^62 costs millennia even at ten billion hashes a second, so
 *    the work factor buys nothing here. This is the same reasoning behind
 *    Laravel storing Sanctum tokens as a plain SHA-256, and it only holds while
 *    the codes really are random — a partner who wants to use their members'
 *    birthdays as activation codes has to be told no.
 *
 * 2. **bcrypt cannot be done in bulk.** The first real import is 1.3 million
 *    positions. At bcrypt cost 12 that is over a week of CPU before a single
 *    row lands, which is not a tuning problem, it is a different design. HMAC
 *    over the same set is seconds, and lets the whole import run as a handful
 *    of set-based statements.
 *
 * Keyed with the application key so the digest is not a bare hash of the code:
 * somebody who walks off with a database dump, and nothing else, cannot even
 * check a guessed code offline. That costs nothing and removes the one attack
 * a plain SHA-256 would leave open.
 *
 * Rotating APP_KEY invalidates every unclaimed code. That is a real consequence
 * and it is why this uses the key rather than a salt of its own — a rotation
 * already invalidates every encrypted column in the database, so it is an event
 * with a runbook, not a surprise this class adds.
 */
class ActivationCode
{
    /**
     * A digest to store and compare.
     *
     * Deterministic on purpose: it makes the claim lookup an indexed equality
     * check rather than a scan-and-verify, and it is what lets the importer
     * hash a million codes inside one INSERT ... SELECT.
     */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), self::key());
    }

    /** Constant-time comparison. Never `===` on a secret. */
    public static function matches(string $code, ?string $digest): bool
    {
        if ($digest === null || $digest === '') {
            return false;
        }

        return hash_equals($digest, self::hash($code));
    }

    /**
     * Codes are read off paper and typed by hand.
     *
     * Case and whitespace are forgiven; nothing else is. Hyphens are kept
     * because a partner's codes may contain them meaningfully, and stripping
     * them would make "AB-CD" and "ABCD" the same credential.
     */
    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
    }

    /**
     * The Postgres expression that produces the same digest, for bulk work.
     *
     * The importer hashes a million codes inside one statement rather than a
     * million round trips. It must agree with hash() exactly — SpotImportTest
     * commits a real row and checks the digest against hash(), so the two
     * cannot drift apart silently.
     *
     * pgcrypto's hmac() returns bytea; encode(...,'hex') matches PHP's
     * hexadecimal hash_hmac output.
     *
     * The key is bound as hex and decoded in SQL rather than bound directly.
     * A decoded APP_KEY is 32 random bytes and is almost never valid UTF-8, so
     * binding it as a text parameter is rejected outright by Postgres — which
     * is a good failure, but only once, and only if you know why.
     */
    public static function sqlExpression(string $codeColumn): string
    {
        // convert_to(...) because pgcrypto overloads hmac() on (bytea, bytea)
        // and (text, text) but not the mixture — and the key has to be bytea,
        // since it is raw bytes.
        return "encode(hmac("
            . "convert_to(upper(regexp_replace({$codeColumn}, '\\s', '', 'g')), 'UTF8'), "
            . "decode(?, 'hex'), 'sha256'), 'hex')";
    }

    /** The parameter to bind alongside sqlExpression(). */
    public static function sqlKey(): string
    {
        return bin2hex(self::key());
    }

    /**
     * The HMAC key: the application key, decoded.
     *
     * Public because the bulk path needs it. It is no more exposed by being
     * readable here than it is by config('app.key').
     */
    public static function key(): string
    {
        $key = (string) Config::get('app.key');

        return str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7))
            : $key;
    }
}
