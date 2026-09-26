<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Registration;
use App\Models\AppNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    /**
     * POST /api/attendance/checkin
     * Validates QR token and records photo-based check-in.
     */
    public function checkin(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->recordCheckin($request));
    }

    private function recordCheckin(Request $request)
    {
        $request->validate([
            'qr_token' => 'required|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'photo_data' => ['nullable', new \App\Rules\AttendancePhoto],
        ]);

        if (!$request->hasFile('photo') && empty($request->photo_data)) {
            return response()->json(['status' => 'error', 'message' => 'A photo is required for attendance check-in. Please take a selfie.'], 422);
        }

        $token = $request->qr_token;
        $parts = explode('|', $token);
        $registration = null;

        if (count($parts) === 3) {
            $registrationId = $parts[0];
            $expiresAt = $parts[1];
            $signature = $parts[2];

            $expectedSignature = hash_hmac('sha256', $registrationId . '|' . $expiresAt, config('app.key'));
            if (!hash_equals($expectedSignature, $signature)) {
                \App\Models\AttendanceAuditLog::create([
                    'qr_token' => $token,
                    'action' => 'selfie_checkin',
                    'status' => 'invalid_signature',
                    'ip_address' => request()->ip(),
                    'device_info' => request()->userAgent(),
                ]);
                return response()->json(['status' => 'error', 'message' => 'Invalid QR Code signature.'], 422);
            }

            if (now()->timestamp > $expiresAt) {
                \App\Models\AttendanceAuditLog::create([
                    'registration_id' => $registrationId,
                    'qr_token' => $token,
                    'action' => 'selfie_checkin',
                    'status' => 'expired_token',
                    'ip_address' => request()->ip(),
                    'device_info' => request()->userAgent(),
                ]);
                return response()->json(['status' => 'error', 'message' => 'This QR Code has expired. Please refresh the QR code on the student app.'], 422);
            }

            $registration = Registration::with('event', 'user')->lockForUpdate()->find($registrationId);
        } else {
            $registration = Registration::with('event', 'user')
                ->where('qr_token', $token)
                ->lockForUpdate()
                ->first();
        }

        if (!$registration) {
            \App\Models\AttendanceAuditLog::create([
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'invalid_token',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Invalid QR code.'], 404);
        }

        Gate::authorize('view', $registration);
        abort_unless($registration->event?->status === 'published', 422, 'This event is no longer active.');

        if ($registration->status === 'cancelled') {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'cancelled_registration',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Registration is cancelled.'], 422);
        }

        if ($registration->isExpired()) {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'expired_registration',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'QR code has expired.'], 422);
        }

        // ── Live-window check (Forced PHT to avoid server sync issues) ─────
        $event       = $registration->event;
        $now         = \Carbon\Carbon::now('Asia/Manila');
        $today       = $now->toDateString();

        if ($event->event_date->toDateString() !== $today) {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'outside_event_window',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            \Illuminate\Support\Facades\Log::warning("Attendance Rejected (Date): User [{$registration->user->id}] attempted check-in for event [{$event->id}] scheduled for [{$event->event_date->toDateString()}] but server today is [{$today}].");
            return response()->json([
                'status'  => 'error',
                'message' => 'Attendance can only be recorded on the day of the event (' . $event->event_date->format('M d, Y') . ').',
            ], 422);
        }

        $eventStartTime = \Carbon\Carbon::parse($event->event_date->toDateString() . ' ' . $event->start_time, 'Asia/Manila');
        $eventEndTime   = \Carbon\Carbon::parse($event->event_date->toDateString() . ' ' . $event->end_time, 'Asia/Manila');
        $earlyWindow    = $eventStartTime->copy()->subMinutes(30);

        if ($now->gt($eventEndTime->copy()->addHours(3))) {
            return response()->json(['status' => 'error', 'message' => 'The attendance window has closed.'], 422);
        }

        if ($now->lt($earlyWindow)) {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'outside_event_window',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            $start = $eventStartTime->format('h:i A');
            \Illuminate\Support\Facades\Log::warning("Attendance Rejected (Time): User [{$registration->user->id}] attempted check-in for event [{$event->id}] at [{$now->format('H:i:s')}] but window starts at [{$event->start_time}].");
            return response()->json([
                'status'  => 'error',
                'message' => "Attendance check-in opens 30 minutes before the event starts ({$start}).",
            ], 422);
        }

        // ── Second Scan: Record Check-Out (Time Out) ─────────────────────
        if ($registration->attendance) {
            $attendance = $registration->attendance;

            if (!$attendance->checked_out_at) {
                $checkoutPhotoPath = null;
                if ($request->hasFile('photo')) {
                    $checkoutPhotoPath = $request->file('photo')->store('attendance-photos/' . $registration->event_id, 's3');
                } elseif ($request->filled('photo_data')) {
                    $image_parts = explode(';base64,', $request->photo_data);
                    if (count($image_parts) >= 2) {
                        $image_type_aux = explode('image/', $image_parts[0]);
                        if (count($image_type_aux) >= 2) {
                            $image_type = strtolower($image_type_aux[1]);
                            $allowed = ['jpeg', 'jpg', 'png', 'webp'];
                            if (in_array($image_type, $allowed)) {
                                $image_base64 = base64_decode($image_parts[1]);
                                if (strlen($image_base64) <= 5 * 1024 * 1024) {
                                    $fileName = 'attendance-photos/' . $registration->event_id . '/checkout_' . uniqid() . '.' . $image_type;
                                    \Illuminate\Support\Facades\Storage::disk('s3')->put($fileName, $image_base64);
                                    $checkoutPhotoPath = $fileName;
                                }
                            }
                        }
                    }
                }

                $attendance->update([
                    'checkout_photo_path' => $checkoutPhotoPath,
                    'checked_out_at'      => now(),
                ]);

                \App\Models\AttendanceAuditLog::create([
                    'user_id' => $registration->user_id,
                    'event_id' => $registration->event_id,
                    'registration_id' => $registration->id,
                    'qr_token' => $token,
                    'action' => 'selfie_checkout',
                    'status' => 'success',
                    'ip_address' => request()->ip(),
                    'device_info' => request()->userAgent(),
                ]);

                AppNotification::create([
                    'user_id' => $registration->user_id,
                    'type'    => 'attendance_checked_out',
                    'title'   => 'Check-Out Recorded',
                    'message' => "Your check-out (Time Out) for {$registration->event->title} has been recorded.",
                    'data'    => ['event_id' => $registration->event_id, 'attendance_id' => $attendance->id],
                ]);

                return response()->json([
                    'status'    => 'success',
                    'scan_type' => 'time_out',
                    'message'   => 'Check-out (Time Out) recorded successfully.',
                    'data'      => $attendance->fresh(),
                ], 200);
            }

            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'selfie_checkin',
                'status' => 'already_completed',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);

            return response()->json([
                'status'    => 'already_completed',
                'scan_type' => 'completed',
                'message'   => 'Attendance check-in and check-out already completed for this event.',
                'data'      => $registration->attendance,
            ]);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store(
                'attendance-photos/' . $registration->event_id, 's3'
            );
        } elseif ($request->filled('photo_data')) {
            $image_parts = explode(';base64,', $request->photo_data);
            if (count($image_parts) >= 2) {
                $image_type_aux = explode('image/', $image_parts[0]);
                if (count($image_type_aux) >= 2) {
                    $image_type = strtolower($image_type_aux[1]);
                    $allowed = ['jpeg', 'jpg', 'png', 'webp'];
                    if (in_array($image_type, $allowed)) {
                        $image_base64 = base64_decode($image_parts[1]);
                        if (strlen($image_base64) <= 5 * 1024 * 1024) {
                            $fileName = 'attendance-photos/' . $registration->event_id . '/' . uniqid() . '.' . $image_type;
                            \Illuminate\Support\Facades\Storage::disk('s3')->put($fileName, $image_base64);
                            $photoPath = $fileName;
                        }
                    }
                }
            }
        }

        $attendance = Attendance::create([
            'registration_id' => $registration->id,
            'photo_path'      => $photoPath,
            'checked_in_at'   => now(),
            'status'          => 'pending',
        ]);

        \App\Models\AttendanceAuditLog::create([
            'user_id' => $registration->user_id,
            'event_id' => $registration->event_id,
            'registration_id' => $registration->id,
            'qr_token' => $token,
            'action' => 'selfie_checkin',
            'status' => 'success',
            'ip_address' => request()->ip(),
            'device_info' => request()->userAgent(),
        ]);

        AppNotification::create([
            'user_id' => $registration->user_id,
            'type'    => 'attendance_recorded',
            'title'   => 'Attendance Recorded',
            'message' => "Your attendance for {$registration->event->title} has been recorded. Awaiting verification.",
            'data'    => ['event_id' => $registration->event_id, 'attendance_id' => $attendance->id],
        ]);

        return response()->json([
            'status'    => 'success',
            'scan_type' => 'time_in',
            'message'   => 'Attendance (Time In) recorded successfully',
            'data'      => $attendance,
        ], 201);
    }

    /**
     * GET /api/events/{id}/attendance — list attendees for an event
     */
    public function eventAttendance($eventId)
    {
        Gate::authorize('update', Event::findOrFail($eventId));
        $attendances = Attendance::with(['registration.user.course', 'registration.user.section', 'verifiedBy'])
            ->whereHas('registration', fn($q) => $q->where('event_id', $eventId))
            ->get();

        return response()->json($attendances);
    }

    /**
     * GET /api/attendance/{id}
     */
    public function show($id)
    {
        $attendance = Attendance::with(['registration.user', 'registration.event', 'verifiedBy'])
            ->findOrFail($id);
        Gate::authorize('update', $attendance->registration->event);
        return response()->json($attendance);
    }

    /**
     * PUT /api/attendance/{id}/verify
     */
    public function verify(Request $request, $id)
    {
        $attendance = Attendance::with('registration.event')->findOrFail($id);

        // Ownership check: only event organizer or admin
        $event = $attendance->registration->event;
        if ($event->organizer_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'You can only verify attendance for your own events.'], 403);
        }

        $request->validate([
            'status' => 'required|in:verified,rejected',
            'notes'  => 'nullable|string',
        ]);

        $attendance->update([
            'status'      => $request->status,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'notes'       => $request->notes,
        ]);

        $statusLabel = ucfirst($request->status);
        AppNotification::create([
            'user_id' => $attendance->registration->user_id,
            'type'    => 'attendance_' . $request->status,
            'title'   => "Attendance {$statusLabel}",
            'message' => "Your attendance for {$attendance->registration->event->title} has been {$request->status}.",
            'data'    => ['attendance_id' => $attendance->id],
        ]);

        return response()->json(['status' => 'success', 'attendance' => $attendance->fresh('verifiedBy')]);
    }
}
