<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\AppNotification;
use App\Models\User;
use App\Models\EquipmentRequest;
use App\Exports\AttendanceExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class OrganizerController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!Auth::check() || !in_array(Auth::user()->role, ['organizer', 'student_development', 'admin'])) {
                    abort(403, 'Access denied. Organizers only.');
                }
                return $next($request);
            }),
        ];
    }

    public function dashboard()
    {
        $user       = Auth::user();
        $myEvents   = Event::where('organizer_id', $user->id)->upcoming()->take(5)->get()
            ->map(function ($e) { $e->reg_count = $e->registeredCount(); return $e; });
        $stats = [
            'total_events'    => Event::where('organizer_id', $user->id)->count(),
            'upcoming_events' => Event::where('organizer_id', $user->id)->upcoming()->count(),
            'total_regs'      => Registration::whereHas('event', fn($q) => $q->where('organizer_id', $user->id))->count(),
            'verified_att'    => Attendance::whereHas('registration.event', fn($q) => $q->where('organizer_id', $user->id))
                ->where('status', 'verified')->count(),
        ];
        $pendingVerifications = Attendance::with(['registration.user', 'registration.event'])
            ->whereHas('registration.event', fn($q) => $q->where('organizer_id', $user->id))
            ->where('status', 'pending')->take(20)->get();

        return view('organizer.dashboard', compact('user', 'myEvents', 'stats', 'pendingVerifications'));
    }

    public function events(Request $request)
    {
        $user = Auth::user();
        $tab  = $request->query('tab', 'upcoming');

        $query = Event::where('organizer_id', $user->id)->with('latestProposal')->withCount('registrations');
        if ($tab === 'past') {
            $query->where('event_date', '<', now()->toDateString())->orderByDesc('event_date');
        } else {
            $query->where('event_date', '>=', now()->toDateString())->orderBy('event_date', 'asc');
        }

        $events = $query->paginate(9)->withQueryString();
        $upcomingCount = Event::where('organizer_id', $user->id)->where('event_date', '>=', now()->toDateString())->count();
        $pastCount     = Event::where('organizer_id', $user->id)->where('event_date', '<', now()->toDateString())->count();

        return view('organizer.events', compact('events', 'tab', 'upcomingCount', 'pastCount'));
    }

    public function createEvent()
    {
        return view('organizer.event-form', ['event' => null]);
    }

    public function storeEvent(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'venue'       => 'required|string',
            'venue_type'  => 'required|string|max:255',
            'custom_venue_type' => 'nullable|required_if:venue_type,Other|string|max:255',
            'event_date'  => 'required|date|after_or_equal:today',
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => [
                'required',
                'date_format:H:i',
                'after:start_time',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->filled('start_time') && $value) {
                        $start = \Carbon\Carbon::parse($request->input('start_time'));
                        $end   = \Carbon\Carbon::parse($value);
                        if ($start->diffInMinutes($end, false) < 60) {
                            $fail('The event duration must be at least 1 hour (60 minutes).');
                        }
                    }
                },
            ],
            'capacity'    => 'required|integer|min:1',
            'category'    => 'nullable|string',
            'is_featured' => 'boolean',
            'poster'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'equipment_items' => 'nullable|array|max:20',
            'equipment_items.*.item_name' => 'nullable|string|max:150',
            'equipment_items.*.quantity' => 'nullable|integer|min:1|max:10000',
            'equipment_items.*.purpose' => 'nullable|string|max:255',
        ]);

        if ($validated['venue_type'] === 'Other') {
            $validated['venue_type'] = $validated['custom_venue_type'];
        }
        unset($validated['custom_venue_type']);

        $poster_path = null;
        if ($request->hasFile('poster')) {
            try {
                $poster_path = $request->file('poster')->store('posters', 's3');
            } catch (\Throwable $e) {
                $poster_path = $request->file('poster')->store('posters', 'public');
            }
        }

        // --- Duplicate venue/time conflict check ---
        $conflict = Event::where('venue', $validated['venue'])
            ->where('event_date', $validated['event_date'])
            ->where('status', 'published')
            ->where(function ($q) use ($validated) {
                $q->where('start_time', '<', $validated['end_time'])
                  ->where('end_time',   '>', $validated['start_time']);
            })
            ->first();

        if ($conflict) {
            return back()->withInput()->withErrors([
                'venue' => 'This venue is already booked for "' . $conflict->title . '" from '
                    . \Carbon\Carbon::parse($conflict->start_time)->format('h:i A')
                    . ' to ' . \Carbon\Carbon::parse($conflict->end_time)->format('h:i A')
                    . ' on that date. Please choose a different venue or time slot.',
            ]);
        }

        $equipmentItems = $validated['equipment_items'] ?? [];
        unset($validated['equipment_items']);

        $event = DB::transaction(function () use ($validated, $poster_path, $request, $equipmentItems) {
            $event = Event::create([
                ...$validated,
                'organizer_id'  => Auth::id(),
                'poster_path'   => $poster_path,
                'status'        => 'draft',
                'is_featured'   => $request->boolean('is_featured'),
            ]);

            $this->storeEquipmentRequests($event, $equipmentItems);
            return $event;
        });

        User::log('create_event', $event, null, $event->toArray());

        return redirect()->route('proposal.create', $event)
            ->with('success', 'Event request saved as a draft. Complete and submit its proposal for approval.');
    }

    public function editEvent($id)
    {
        $event = Event::where('organizer_id', Auth::id())
            ->with(['equipmentRequests' => fn ($query) => $query->where('status', 'requested')])
            ->findOrFail($id);
        return view('organizer.event-form', compact('event'));
    }

    public function updateEvent(Request $request, $id)
    {
        $event = Event::where('organizer_id', Auth::id())->findOrFail($id);

        // The organizer cannot change workflow status; publication is controlled by signatories.
        $allowedStatuses = Auth::user()->role === 'admin'
            ? 'in:draft,cancelled,published,completed'
            : 'in:cancelled,published';

        $validated = $request->validate([
            'title'       => 'required|string',
            'description' => 'nullable|string',
            'venue'       => 'required|string',
            'venue_type'  => 'required|string|max:255',
            'custom_venue_type' => 'nullable|required_if:venue_type,Other|string|max:255',
            'event_date'  => 'required|date|after_or_equal:today',
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => [
                'required',
                'date_format:H:i',
                'after:start_time',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->filled('start_time') && $value) {
                        $start = \Carbon\Carbon::parse($request->input('start_time'));
                        $end   = \Carbon\Carbon::parse($value);
                        if ($start->diffInMinutes($end, false) < 60) {
                            $fail('The event duration must be at least 1 hour (60 minutes).');
                        }
                    }
                },
            ],
            'capacity'    => 'required|integer|min:1',
            'status'      => $allowedStatuses,
            'category'    => 'nullable|string',
            'poster'      => 'nullable|image|max:4096',
            'equipment_items' => 'nullable|array|max:20',
            'equipment_items.*.item_name' => 'nullable|string|max:150',
            'equipment_items.*.quantity' => 'nullable|integer|min:1|max:10000',
            'equipment_items.*.purpose' => 'nullable|string|max:255',
        ]);

        if ($validated['venue_type'] === 'Other') {
            $validated['venue_type'] = $validated['custom_venue_type'];
        }
        unset($validated['custom_venue_type']);

        if (Auth::user()->role !== 'admin') {
            unset($validated['status']);
        }

        $equipmentItems = $validated['equipment_items'] ?? [];
        unset($validated['equipment_items']);

        if ($request->hasFile('poster')) {
            try {
                if ($event->poster_path) \Illuminate\Support\Facades\Storage::disk('s3')->delete($event->poster_path);
                $validated['poster_path'] = $request->file('poster')->store('posters', 's3');
            } catch (\Throwable $e) {
                $validated['poster_path'] = $request->file('poster')->store('posters', 'public');
            }
        }
        $old = $event->toArray();
        DB::transaction(function () use ($event, $validated, $equipmentItems) {
            $event->update($validated);
            $event->equipmentRequests()->where('status', 'requested')->delete();
            $this->storeEquipmentRequests($event, $equipmentItems);
        });

        User::log('update_event', $event, $old, $event->toArray());

        return redirect()->route('organizer.events')->with('success', 'Event updated!');
    }

    private function storeEquipmentRequests(Event $event, array $items): void
    {
        foreach ($items as $item) {
            $name = trim((string) ($item['item_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            EquipmentRequest::create([
                'event_id' => $event->id,
                'requested_by' => Auth::id(),
                'item_name' => $name,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'purpose' => $item['purpose'] ?? null,
                'status' => 'requested',
            ]);
        }
    }

    public function deleteEvent($id)
    {
        $event = Event::where('organizer_id', Auth::id())->findOrFail($id);
        $old = $event->toArray();
        $event->delete();
        User::log('delete_event', $event, $old, null);
        return back()->with('success', 'Event deleted.');
    }

    public function attendees($id)
    {
        $event = Event::where('organizer_id', Auth::id())->findOrFail($id);
        $attendances = Attendance::with(['registration.user.course', 'registration.user.section', 'verifiedBy'])
            ->whereHas('registration', fn($q) => $q->where('event_id', $id))->get();
        $registrations = Registration::with('user.course', 'user.section')
            ->where('event_id', $id)->where('status', '!=', 'cancelled')->get();

        return view('organizer.attendees', compact('event', 'attendances', 'registrations'));
    }

    public function verify(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:verified,rejected',
            'notes'  => 'nullable|string|max:500',
        ]);

        $attendance = Attendance::whereHas('registration.event', fn($q) => $q->where('organizer_id', Auth::id()))
            ->findOrFail($id);
        $old = $attendance->toArray();
        $attendance->update([
            'status'      => $request->status,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
            'notes'       => $request->notes,
        ]);
        User::log('verify_attendance', $attendance, $old, $attendance->toArray());
        return back()->with('success', 'Attendance ' . $request->status . '.');
    }

    public function exportPdf($eventId)
    {
        $event       = Event::where('organizer_id', Auth::id())->findOrFail($eventId);
        $attendances = Attendance::with(['registration.user.course', 'registration.user.section'])
            ->whereHas('registration', fn($q) => $q->where('event_id', $eventId))->get();
        
        User::log('export_attendance_pdf', $event, null, ['format' => 'pdf']);

        $pdf = Pdf::loadView('reports.attendance-pdf', compact('event', 'attendances'))->setPaper('a4');
        return $pdf->download("attendance-{$eventId}.pdf");
    }

    public function exportExcel($eventId)
    {
        $event = Event::where('organizer_id', Auth::id())->findOrFail($eventId);
        User::log('export_attendance_excel', $event, null, ['format' => 'excel']);
        return Excel::download(new AttendanceExport($eventId), "attendance-{$eventId}.xlsx");
    }

    public function allAttendees(Request $request)
    {
        $user = Auth::user();
        $myEventIds = Event::where('organizer_id', $user->id)->pluck('id');

        $query = Registration::with(['user.course', 'user.section', 'event', 'attendance'])
            ->whereIn('event_id', $myEventIds)
            ->where('status', '!=', 'cancelled');

        if ($request->filled('event_id')) {
            $query->where('event_id', $request->event_id);
        }
        if ($request->filled('status')) {
            if ($request->status === 'attended') {
                $query->whereHas('attendance', fn($q) => $q->where('status', 'verified'));
            } elseif ($request->status === 'not_attended') {
                $query->whereDoesntHave('attendance', fn($q) => $q->where('status', 'verified'));
            }
        }

        $registrations = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $myEvents = Event::where('organizer_id', $user->id)->orderByDesc('event_date')->get();

        return view('organizer.all-attendees', compact('registrations', 'myEvents'));
    }

    public function analytics()
    {
        $user = Auth::user();
        $events = Event::where('organizer_id', $user->id)
            ->withCount([
                'registrations',
                'registrations as verified_count' => fn($q) => $q->whereHas('attendance', fn($sq) => $sq->where('status', 'verified'))
            ])
            ->orderByDesc('event_date')->get();
        return view('organizer.analytics', compact('events'));
    }

    // Venue Reservations have been moved to StudentDepartmentController as per workflow update.

    /**
     * GET previews a scan; POST records it with CSRF and replay protection.
     * Universal camera-based QR check-in & check-out.
     * 1st Scan: Time In (Check-in)
     * 2nd Scan: Time Out (Check-out)
     */
    public function scanQr(Request $request, $token)
    {
        if ($request->isMethod('post')) {
            $request->validate(['scan_id' => 'required|uuid']);
        }

        return \Illuminate\Support\Facades\DB::transaction(
            fn () => $this->processQrScan($request, $token)
        );
    }

    private function processQrScan(Request $request, string $token)
    {
        $parts = explode('|', $token);
        $registration = null;
        $isRotating = false;

        if (count($parts) === 3) {
            $registrationId = $parts[0];
            $expiresAt = $parts[1];
            $signature = $parts[2];

            $expectedSignature = hash_hmac('sha256', $registrationId . '|' . $expiresAt, config('app.key'));
            if (!hash_equals($expectedSignature, $signature)) {
                \App\Models\AttendanceAuditLog::create([
                    'qr_token' => $token,
                    'action' => 'scan_qr',
                    'status' => 'invalid_signature',
                    'ip_address' => request()->ip(),
                    'device_info' => request()->userAgent(),
                ]);
                return view('organizer.scan-result', ['status' => 'error', 'message' => 'Invalid QR Code signature. Potential tampering detected.']);
            }

            if (now()->timestamp > $expiresAt) {
                \App\Models\AttendanceAuditLog::create([
                    'registration_id' => $registrationId,
                    'qr_token' => $token,
                    'action' => 'scan_qr',
                    'status' => 'expired_token',
                    'ip_address' => request()->ip(),
                    'device_info' => request()->userAgent(),
                ]);
                return view('organizer.scan-result', ['status' => 'error', 'message' => 'This QR Code has expired. Please refresh the QR code on the student app.']);
            }

            $registration = Registration::with(['event', 'user', 'attendance'])->lockForUpdate()->find($registrationId);
            $isRotating = true;
        } else {
            $registration = Registration::with(['event', 'user', 'attendance'])->where('qr_token', $token)->lockForUpdate()->first();
        }

        if (!$registration) {
            \App\Models\AttendanceAuditLog::create([
                'qr_token' => $token,
                'action' => 'scan_qr',
                'status' => 'invalid_token',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return view('organizer.scan-result', ['status' => 'error', 'message' => 'Invalid QR Code. Registration not found.']);
        }

        \Illuminate\Support\Facades\Gate::authorize('update', $registration->event);
        if ($registration->status === 'cancelled' || $registration->event->status !== 'published') {
            return view('organizer.scan-result', ['status' => 'error', 'message' => 'This registration or event is no longer active.']);
        }

        if ($registration->isExpired()) {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'scan_qr',
                'status' => 'expired_registration',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return view('organizer.scan-result', ['status' => 'error', 'message' => 'This QR Code has expired. Registration is no longer valid.']);
        }

        $event = $registration->event;
        $now = \Carbon\Carbon::now('Asia/Manila');
        $today = $now->toDateString();

        $eventDate = $event->event_date instanceof \DateTimeInterface
            ? $event->event_date->format('Y-m-d')
            : \Carbon\Carbon::parse($event->event_date)->toDateString();

        if ($eventDate !== $today) {
            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'scan_qr',
                'status' => 'outside_event_window',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);
            return view('organizer.scan-result', [
                'status' => 'error',
                'message' => 'Attendance can only be scanned on the day of the event (' . \Carbon\Carbon::parse($eventDate)->format('M d, Y') . ').',
            ]);
        }

        $eventStartTime = \Carbon\Carbon::parse($eventDate . ' ' . $event->start_time, 'Asia/Manila');
        $eventEndTime   = \Carbon\Carbon::parse($eventDate . ' ' . $event->end_time, 'Asia/Manila');

        // Early check-in allowed 30 mins prior; check-out allowed up to 3 hours after event end (or entire event day)
        $earlyWindow = $eventStartTime->copy()->subMinutes(30);
        $lateWindow  = $eventEndTime->copy()->addHours(3);

        if ($now->gt($lateWindow)) {
            return view('organizer.scan-result', ['status' => 'error', 'message' => 'The attendance window has closed.']);
        }

        if ($now->lt($earlyWindow)) {
            $start = $eventStartTime->format('h:i A');
            return view('organizer.scan-result', [
                'status' => 'error',
                'message' => "Attendance scanner opens 30 minutes before event start ({$start}).",
            ]);
        }

        if ($request->isMethod('get')) {
            return view('organizer.scan-confirm', compact('registration', 'token'));
        }

        $scanKey = 'attendance-scan:'.Auth::id().':'.$request->input('scan_id');
        if (!\Illuminate\Support\Facades\Cache::add($scanKey, true, now()->addDay())) {
            return view('organizer.scan-result', ['status' => 'warning', 'message' => 'This scan has already been processed.']);
        }

        // ── Case 1: First Scan -> Time In (Check-in) ─────────────────────
        if (!$registration->attendance) {
            $attendance = Attendance::create([
                'registration_id' => $registration->id,
                'checked_in_at'   => now(),
                'status'          => 'verified',
                'verified_by'     => Auth::id(),
                'verified_at'     => now(),
            ]);

            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'scan_time_in',
                'status' => 'success',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);

            return view('organizer.scan-result', [
                'status'       => 'time_in',
                'message'      => 'Time In Recorded Successfully!',
                'registration' => $registration,
                'attendance'   => $attendance,
            ]);
        }

        // ── Case 2: Second Scan -> Time Out (Check-out) ──────────────────
        $attendance = $registration->attendance;
        if (!$attendance->checked_out_at) {
            $attendance->update([
                'checked_out_at' => now(),
            ]);

            \App\Models\AttendanceAuditLog::create([
                'user_id' => $registration->user_id,
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
                'qr_token' => $token,
                'action' => 'scan_time_out',
                'status' => 'success',
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent(),
            ]);

            return view('organizer.scan-result', [
                'status'       => 'time_out',
                'message'      => 'Time Out Recorded Successfully!',
                'registration' => $registration,
                'attendance'   => $attendance->fresh(),
            ]);
        }

        // ── Case 3: Subsequent Scans -> Already Completed Both In & Out ──
        \App\Models\AttendanceAuditLog::create([
            'user_id' => $registration->user_id,
            'event_id' => $registration->event_id,
            'registration_id' => $registration->id,
            'qr_token' => $token,
            'action' => 'scan_qr',
            'status' => 'already_completed',
            'ip_address' => request()->ip(),
            'device_info' => request()->userAgent(),
        ]);

        return view('organizer.scan-result', [
            'status'       => 'already_completed',
            'message'      => 'Student has already completed both Time In and Time Out.',
            'registration' => $registration,
            'attendance'   => $attendance,
        ]);
    }
}
