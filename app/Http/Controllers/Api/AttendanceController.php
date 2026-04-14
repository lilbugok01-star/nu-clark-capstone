<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Registration;
use App\Models\AppNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    /**
     * POST /api/attendance/checkin
     * Validates QR token and records photo-based check-in.
     */
    public function checkin(Request $request)
    {
        $request->validate([
            'qr_token' => 'required|string',
            'photo'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // Find the registration by QR token
        $registration = Registration::with('event', 'user')
            ->where('qr_token', $request->qr_token)
            ->first();

        if (!$registration) {
            return response()->json(['status' => 'error', 'message' => 'Invalid QR code.'], 404);
        }

        if ($registration->status === 'cancelled') {
            return response()->json(['status' => 'error', 'message' => 'Registration is cancelled.'], 422);
        }

        if ($registration->isExpired()) {
            return response()->json(['status' => 'error', 'message' => 'QR code has expired.'], 422);
        }

        // ── Live-window check ──────────────────────────────────────────────
        $event       = $registration->event;
        $now         = Carbon::now();
        $today       = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        if ($event->event_date->toDateString() !== $today) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Attendance can only be recorded on the day of the event (' . $event->event_date->format('M d, Y') . ').',
            ], 422);
        }

        if ($currentTime < $event->start_time || $currentTime > $event->end_time) {
            $start = Carbon::parse($event->start_time)->format('h:i A');
            $end   = Carbon::parse($event->end_time)->format('h:i A');
            return response()->json([
                'status'  => 'error',
                'message' => "Attendance is only open during the event window ({$start} – {$end}).",
            ], 422);
        }
        // ────────────────────────────────────────────────────────────────────

        // Check if already checked in
        if ($registration->attendance) {
            return response()->json([
                'status'  => 'already_checked_in',
                'message' => 'Attendance already recorded.',
                'data'    => $registration->attendance,
            ]);
        }

        // Store photo
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store(
                'attendance-photos/' . $registration->event_id, 's3'
            );
        }

        $attendance = Attendance::create([
            'registration_id' => $registration->id,
            'photo_path'      => $photoPath,
            'checked_in_at'   => now(),
            'status'          => 'pending', // awaiting organizer verification
        ]);

        // Notify student
        AppNotification::create([
            'user_id' => $registration->user_id,
            'type'    => 'attendance_recorded',
            'title'   => 'Attendance Recorded',
            'message' => "Your attendance for {$registration->event->title} has been recorded. Awaiting verification.",
            'data'    => ['event_id' => $registration->event_id, 'attendance_id' => $attendance->id],
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Attendance recorded',
            'data'    => $attendance,
        ], 201);
    }

    /**
     * GET /api/events/{id}/attendance — list attendees for an event
     */
    public function eventAttendance($eventId)
    {
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
