<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restricts account identifiers to the email form used by this system before
 * they reach authentication/database queries. Database access still uses bound
 * parameters; this is an independent validation layer for scanner payloads.
 */
class SafeEmailIdentifier implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = (string) $value;
        if (strlen($email) > 255 || !preg_match('/\A[A-Za-z0-9._%+\-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\z/D', $email)) {
            $fail('The :attribute must be a valid email address.');
        }
    }
}
