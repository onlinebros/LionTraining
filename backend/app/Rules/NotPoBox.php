<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FedEx does not deliver to PO Boxes.
 *
 * Caught at the form so the buyer hears it straight away, whether or not the
 * FedEx address check is available. FedEx's own POBox flag catches the
 * spellings this misses.
 */
class NotPoBox implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/\b(p\.?\s*o\.?\s*b(ox)?|post\s+office\s+box)\b/i', $value)) {
            $fail('FedEx cannot deliver to a PO Box. Please enter a street address.');
        }
    }
}
