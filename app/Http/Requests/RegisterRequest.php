<?php

namespace App\Http\Requests;

use App\Rules\SafeEmailIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitize input before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name'  => trim(str_replace(["\0", "\r", "\n"], '', (string) $this->input('first_name'))),
            'middle_name' => $this->filled('middle_name') ? trim(str_replace(["\0", "\r", "\n"], '', (string) $this->input('middle_name'))) : null,
            'surname'     => trim(str_replace(["\0", "\r", "\n"], '', (string) $this->input('surname'))),
            'email'       => strtolower(trim(str_replace(["\0", "\r", "\n"], '', (string) $this->input('email')))),
            'student_id'  => trim((string) $this->input('student_id')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'surname'     => 'required|string|max:100',
            'email'      => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                new SafeEmailIdentifier(),
                'unique:users,email',
                function ($attribute, $value, $fail) {
                    if (!str_ends_with(strtolower($value), '@students.nu-clark.edu.ph')) {
                        $fail('Only official NU Clark student emails (@students.nu-clark.edu.ph) are allowed.');
                    }
                },
            ],
            'password'   => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'student_id' => ['required', 'string', 'unique:users,student_id', 'regex:/^\d{4}-\d{6}$/'],
            'course_id'  => 'required|exists:courses,id',
            'section_id' => 'required|exists:sections,id',
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
