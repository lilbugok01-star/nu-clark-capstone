<?php

namespace App\Http\Requests;

use App\Rules\SafeEmailIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitize input before validation to neutralize null-byte and control character injections.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim(str_replace(["\0", "\r", "\n"], '', (string) $this->input('email')))),
            ]);
        }
        if ($this->has('password')) {
            $this->merge([
                'password' => str_replace("\0", '', (string) $this->input('password')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier()],
            'password' => 'required|string|max:255',
        ];
    }
}
