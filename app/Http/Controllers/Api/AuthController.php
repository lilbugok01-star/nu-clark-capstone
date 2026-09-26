<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AppNotification;
use App\Mail\VerificationCodeMail;
use App\Mail\PasswordResetMail;
use App\Rules\SafeEmailIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'surname'     => 'required|string|max:255',
            'email'      => [
                'required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier(), 'unique:users,email',
                function ($attribute, $value, $fail) {
                    if (!str_ends_with(strtolower($value), '@students.nu-clark.edu.ph')) {
                        $fail('Only official NU Clark student emails (@students.nu-clark.edu.ph) are allowed.');
                    }
                },
            ],
            'password'   => [
                'required',
                'confirmed',
                PasswordRule::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'student_id' => 'required|string|unique:users,student_id|regex:/^\d{4}-\d{6}$/',
            'course_id'  => 'required|exists:courses,id',
            'section_id' => 'required|exists:sections,id',
        ], [
            'student_id.regex' => 'The Student ID format must be YYYY-NNNNNN (e.g. 2023-190866).',
        ]);

        // Generate verification code
        $code = sprintf("%06d", mt_rand(100000, 999999));

        $user = User::create([
            'first_name'               => $validated['first_name'],
            'middle_name'              => $validated['middle_name'] ?? null,
            'surname'                  => $validated['surname'],
            'email'                    => $validated['email'],
            'password'                 => Hash::make($validated['password']),
            'role'                     => 'student',
            'student_id'               => $validated['student_id'],
            'course_id'                => $validated['course_id'],
            'section_id'               => $validated['section_id'],
            'email_verification_code'  => $code,
        ]);

        // Welcome notification
        AppNotification::create([
            'user_id' => $user->id,
            'type'    => 'welcome',
            'title'   => 'Welcome to NU Clark Events!',
            'message' => "Hi {$user->full_name}, your account has been created. A verification code has been sent to {$user->email}.",
        ]);

        // Send verification code email
        try {
            VerificationCodeMail::sendCode($user, $code);
        } catch (\Exception $e) {
            Log::error('Failed to send verification email (API): ' . $e->getMessage());
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Registration successful. A verification code has been sent to your email.',
            'user'    => $user->load('course', 'section'),
            'token'   => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier()],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($validated)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'user'   => $user->load('course', 'section'),
            'token'  => $token,
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke all tokens for this user (safe Sanctum logout)
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user()->load('course', 'section'));
    }

    /**
     * Verify email with 6-digit code (API)
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 200);
        }

        if ($user->email_verification_code === $request->code) {
            $user->forceFill([
                'email_verified_at'       => now(),
                'email_verification_code' => null,
            ])->save();

            User::log('verify_email', $user);

            return response()->json([
                'status'  => 'success',
                'message' => 'Email verified successfully!',
            ]);
        }

        return response()->json([
            'message' => 'The verification code is invalid.',
        ], 422);
    }

    /**
     * Resend verification code via email (API)
     */
    public function resendVerificationCode(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 200);
        }

        $code = sprintf("%06d", mt_rand(100000, 999999));

        $user->update([
            'email_verification_code' => $code,
        ]);

        try {
            VerificationCodeMail::sendCode($user, $code);
        } catch (\Exception $e) {
            Log::error('Failed to resend verification email (API): ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send verification email: ' . $e->getMessage(),
            ], 500);
        }

        User::log('resend_verification_code', $user);

        return response()->json([
            'status'  => 'success',
            'message' => 'A new verification code has been sent to your email.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier()],
        ]);

        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json(['message' => 'If an account matches that email, a password reset link will be sent.']);
    }

    public function resetPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'token'    => 'required',
            'email' => ['required', 'string', 'email:rfc', 'max:255', new SafeEmailIdentifier()],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        $status = Password::reset(
            $validated,
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function normalizeEmail(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        }
    }
}
