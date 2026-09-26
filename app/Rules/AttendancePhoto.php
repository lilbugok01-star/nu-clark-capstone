<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AttendancePhoto implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bound encoded input before decoding, and check bytes rather than trusting its MIME label.
        if (!is_string($value) || strlen($value) > 7 * 1024 * 1024
            || !preg_match('#^data:image/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=]+)$#D', $value, $matches)) {
            $fail('Please provide a valid JPG, PNG, or WebP photo (maximum 5MB).');
            return;
        }

        $bytes = base64_decode($matches[2], true);
        $info = $bytes === false || strlen($bytes) > 5 * 1024 * 1024 ? false : @getimagesizefromstring($bytes);
        $mime = $matches[1] === 'jpg' ? 'jpeg' : $matches[1];
        if (!$info || ($info['mime'] ?? '') !== 'image/'.$mime) {
            $fail('Please provide a valid JPG, PNG, or WebP photo (maximum 5MB).');
        }
    }
}
