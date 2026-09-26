<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public User $user;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your NU Clark Verification Code: {$this->code}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.verification-code',
            with: [
                'userName' => $this->user->name,
                'code' => $this->code,
            ],
        );
    }

    /**
     * Dispatch verification code email via HTTP API (Resend / Brevo over Port 443) or SMTP fallback.
     */
    public static function sendCode(User $user, string $code): bool
    {
        $resendKey = env('RESEND_KEY');
        $brevoKey  = env('BREVO_KEY');

        $html = view('emails.verification-code', [
            'userName' => $user->full_name,
            'code'     => $code,
        ])->render();

        $subject = "Your NU Clark Verification Code: {$code}";

        if ($resendKey) {
            $fromAddress = env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev');
            $fromName    = env('MAIL_FROM_NAME', 'NU Clark Events');

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$resendKey}",
                'Content-Type'  => 'application/json',
            ])->post('https://api.resend.com/emails', [
                'from'    => "{$fromName} <{$fromAddress}>",
                'to'      => [$user->email],
                'subject' => $subject,
                'html'    => $html,
            ]);

            if ($response->successful()) {
                return true;
            }

            if (!$brevoKey) {
                $errJson = $response->json();
                $msg = $errJson['message'] ?? $response->body();
                throw new \Exception("Resend API: " . $msg);
            }
        }

        if ($brevoKey) {
            $fromAddress = env('MAIL_FROM_ADDRESS', 'no-reply@nu-clark.edu.ph');
            $fromName    = env('MAIL_FROM_NAME', 'NU Clark Events');

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'api-key'      => $brevoKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender'      => ['name' => $fromName, 'email' => $fromAddress],
                'to'          => [['email' => $user->email, 'name' => $user->full_name]],
                'subject'     => $subject,
                'htmlContent' => $html,
            ]);

            if ($response->successful()) {
                return true;
            }
            $errJson = $response->json();
            $msg = $errJson['message'] ?? $response->body();
            throw new \Exception("Brevo API: " . $msg);
        }

        // Standard Laravel Mail fallback (SMTP / log)
        \Illuminate\Support\Facades\Mail::to($user->email)->send(new self($user, $code));
        return true;
    }
}
