<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    public function generate(Request $request, $registrationId)
    {
        $registration = Registration::with('event', 'user')
            ->findOrFail($registrationId);

        Gate::authorize('view', $registration);

        if ($registration->isExpired()) {
            return response()->json(['message' => 'QR code has expired.'], 422);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['message' => 'This registration has been cancelled.'], 422);
        }

        // Generate QR code as SVG (inline)
        $qrContent = json_encode([
            'token'    => $registration->qr_token,
            'event_id' => $registration->event_id,
            'user_id'  => $registration->user_id,
            'exp'      => $registration->qr_expires_at?->timestamp,
        ]);

        $qrCode = QrCode::format('svg')
            ->size(300)
            ->errorCorrection('H')
            ->generate($qrContent);

        return response($qrCode, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function info(Request $request, $registrationId)
    {
        $registration = Registration::with('event', 'user')->findOrFail($registrationId);
        Gate::authorize('view', $registration);

        return response()->json([
            'registration'  => $registration,
            'is_expired'    => $registration->isExpired(),
            'qr_expires_at' => $registration->qr_expires_at,
        ]);
    }
}
