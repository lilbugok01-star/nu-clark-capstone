<?php

namespace App\Http\Requests;

use App\Rules\SafeEmailIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email'      => ['required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier()],
            'student_id' => 'required|string|regex:/^\d{4}-\d{6}$/',
            'password'   => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'student_id.regex' => 'The Student ID format must be YYYY-NNNNNN (e.g. 2023-190866).',
        ];
    }
}
