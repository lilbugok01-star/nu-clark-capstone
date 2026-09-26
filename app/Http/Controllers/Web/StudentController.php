<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

use App\Services\EventRecommendationService;

class StudentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!in_array(Auth::user()->role, ['student', 'admin'])) {
                    abort(403, 'Access denied. Students only.');
                }
                return $next($request);
            }),
        ];
    }

    public function dashboard(Request $request, EventRecommendationService $recommendationService)
    {
        $user            = Auth::user()->load('course', 'section');
        $upcoming        = Registration::with('event')
            ->where('registrations.user_id', $user->id)
            ->whereHas('event', fn($q) => $q->where('event_date', '>=', now()->toDateString())->where('status', 'published'))
            ->where('registrations.status', 'confirmed')
            ->join('events', 'registrations.event_id', '=', 'events.id')
            ->select('registrations.*')
            ->orderBy('events.event_date', 'asc')
            ->orderBy('events.start_time', 'asc')
            ->take(3)->get();

        $totalRegistered = Registration::where('user_id', $user->id)->where('status', 'confirmed')->count();
        $totalAttended   = Registration::where('user_id', $user->id)
            ->whereHas('attendance', fn($q) => $q->where('status', 'verified'))->count();

        $unreadCount = AppNotification::where('user_id', $user->id)->whereNull('read_at')->count();
        $notifications = AppNotification::where('user_id', $user->id)->orderByDesc('created_at')->take(5)->get();

        $recommended = $recommendationService->getRecommendedEvents($user, 4);

        return view('student.dashboard', compact('user', 'upcoming', 'totalRegistered', 'totalAttended', 'unreadCount', 'notifications', 'recommended'));
    }

    public function events(Request $request, EventRecommendationService $recommendationService)
    {
        $tab = $request->query('tab', 'upcoming');

        if ($tab === 'past') {
            $query = Event::with('organizer')->published()
                ->where('event_date', '<', now()->toDateString())
                ->orderByDesc('event_date');
        } else {
            $query = Event::with('organizer')->upcoming();
        }

        if ($request->search)   $query->search($request->search);
        if ($request->category) $query->where('category', $request->category);

        $events     = $query->paginate(12)->withQueryString();
        $categories = Event::published()->whereNotNull('category')->distinct()->pluck('category');

        $upcomingCount = Event::published()->where('event_date', '>=', now()->toDateString())->count();
        $pastCount     = Event::published()->where('event_date', '<', now()->toDateString())->count();

        // Check which ones the student is already registered for
        $registeredIds = Registration::where('user_id', Auth::id())
            ->where('status', '!=', 'cancelled')->pluck('event_id');

        // Personalized recommendations (only for upcoming tab)
        $recommended = ($tab === 'upcoming')
            ? $recommendationService->getRecommendedEvents(Auth::user()->load('course'), 4)
            : collect();

        return view('student.events', compact('events', 'categories', 'registeredIds', 'recommended', 'tab', 'upcomingCount', 'pastCount'));
    }

    public function myEvents(Request $request)
    {
        $tab = $request->query('tab', 'upcoming');

        $allRegistrations = Registration::with(['event', 'attendance'])
            ->where('registrations.user_id', Auth::id())
            ->join('events', 'registrations.event_id', '=', 'events.id')
            ->select('registrations.*')
            ->orderBy('events.event_date', 'asc')
            ->orderBy('events.start_time', 'asc')
            ->get();

        $today = now()->toDateString();
        $upcomingRegistrations = $allRegistrations->filter(fn($r) => $r->event && $r->event->event_date->format('Y-m-d') >= $today);
        $pastRegistrations     = $allRegistrations->filter(fn($r) => $r->event && $r->event->event_date->format('Y-m-d') < $today);

        $registrations = ($tab === 'past') ? $pastRegistrations : $upcomingRegistrations;

        return view('student.my-events', compact('registrations', 'upcomingRegistrations', 'pastRegistrations', 'tab'));
    }

    public function qrCode($registrationId)
    {
        $registration = Registration::with('event')
            ->where('user_id', Auth::id())
            ->findOrFail($registrationId);

        // Generate initial signed rotating token
        $expiresAt = now()->addSeconds(15)->timestamp;
        $payload = $registration->id . '|' . $expiresAt;
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $rotatingToken = $payload . '|' . $signature;

        $url = route('organizer.scan', ['token' => $rotatingToken]);

        $qrCode = QrCode::format('svg')
            ->size(280)
            ->errorCorrection('H')
            ->merge(public_path('assets/img/NU_shield.png'), 0.25, true)
            ->generate($url);

        return view('student.qr-code', compact('registration', 'qrCode'));
    }

    public function getQrToken($registrationId)
    {
        $registration = Registration::with('event')
            ->where('user_id', Auth::id())
            ->findOrFail($registrationId);

        // Generate signed rotating token (valid for 15 seconds)
        $expiresAt = now()->addSeconds(15)->timestamp;
        $payload = $registration->id . '|' . $expiresAt;
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $rotatingToken = $payload . '|' . $signature;

        $url = route('organizer.scan', ['token' => $rotatingToken]);

        $qrSvg = QrCode::format('svg')
            ->size(280)
            ->errorCorrection('H')
            ->merge(public_path('assets/img/NU_shield.png'), 0.25, true)
            ->generate($url);

        return response()->json([
            'token' => $rotatingToken,
            'qr_svg' => (string) $qrSvg,
            'expires_in' => 15,
        ]);
    }

    public function history()
    {
        $registrations = Registration::with(['event', 'attendance'])
            ->where('user_id', Auth::id())
            ->whereHas('event', fn($q) => $q->where('event_date', '<', now()->toDateString()))
            ->orderByDesc('registered_at')
            ->get();

        return view('student.history', compact('registrations'));
    }

    public function profile()
    {
        $user = Auth::user()->load('course', 'section');
        $stats = [
            'registered' => Registration::where('user_id', $user->id)->where('status', 'confirmed')->count(),
            'attended'   => Registration::where('user_id', $user->id)
                ->whereHas('attendance', fn($q) => $q->where('status', 'verified'))->count(),
        ];
        return view('student.profile', compact('user', 'stats'));
    }

    public function register(Request $request, $eventId)
    {
        $event = Event::where('status', 'published')->findOrFail($eventId);

        try {
            app(\App\Services\EventRegistrationService::class)->register(Auth::id(), (int) $eventId);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        AppNotification::create([
            'user_id' => Auth::id(),
            'type'    => 'registration_confirmation',
            'title'   => 'Registered: ' . $event->title,
            'message' => "You have successfully registered for {$event->title}. Your QR code is ready.",
            'data'    => ['event_id' => $eventId],
        ]);

        return redirect()->route('student.my-events')->with('success', 'Successfully registered! Check your QR code.');
    }

    public function cancel(Request $request, $id)
    {
        $reg = Registration::where('user_id', Auth::id())->findOrFail($id);
        if ($reg->event->event_date <= now()->toDateString()) {
            return back()->with('error', 'Cannot cancel past event registrations.');
        }
        $reg->update(['status' => 'cancelled']);
        return back()->with('success', 'Registration cancelled.');
    }

    public function checkin(Request $request)
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->recordCheckin($request));
    }

    private function recordCheckin(Request $request)
    {
        $request->validate([
            'qr_token'   => 'required|string',
            'photo'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'photo_data' => ['nullable', new \App\Rules\AttendancePhoto],
        ]);

        if (!$request->hasFile('photo') && empty($request->photo_data)) {
            return back()->with('error', 'A photo is required for attendance check-in. Please take a selfie.');
        }

        $registration = Registration::with('event')->where('user_id', Auth::id())
            ->where('qr_token', $request->qr_token)->lockForUpdate()->first();

        if (!$registration) {
            return back()->with('error', 'Invalid QR code.');
        }

        if ($registration->status === 'cancelled' || $registration->event?->status !== 'published') {
            return back()->with('error', 'This registration or event is no longer active.');
        }

        if ($registration->isExpired()) {
            return back()->with('error', 'QR code has expired.');
        }

        // ── Live-window check ──────────────────────────────────────────────
        $event      = $registration->event;
        $now        = \Carbon\Carbon::now('Asia/Manila');
        $today      = $now->toDateString();

        $eventDate = $event->event_date instanceof \DateTimeInterface
            ? $event->event_date->format('Y-m-d')
            : \Carbon\Carbon::parse($event->event_date)->toDateString();

        if ($eventDate !== $today) {
            return back()->with('error', 'Attendance can only be submitted on the day of the event (' . \Carbon\Carbon::parse($eventDate)->format('M d, Y') . ').');
        }

        $eventStartTime = \Carbon\Carbon::parse($eventDate . ' ' . $event->start_time, 'Asia/Manila');
        $eventEndTime   = \Carbon\Carbon::parse($eventDate . ' ' . $event->end_time, 'Asia/Manila');

        // Exact time check
        if ($now->lt($eventStartTime) || $now->gt($eventEndTime)) {
            $start = $eventStartTime->format('h:i A');
            $end   = $eventEndTime->format('h:i A');
            return back()->with('error', "Attendance is only open during the event window ({$start} – {$end}).");
        }
        // ────────────────────────────────────────────────────────────────────

        if ($registration->attendance) {
            return back()->with('info', 'Attendance already recorded.');
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('attendance-photos/' . $registration->event_id, 's3');
        } elseif ($request->filled('photo_data')) {
            $image_parts = explode(';base64,', $request->photo_data);
            if (count($image_parts) >= 2) {
                $image_type_aux = explode('image/', $image_parts[0]);
                if (count($image_type_aux) >= 2) {
                    $image_type = strtolower($image_type_aux[1]);
                    // Only allow safe image extensions
                    $allowed = ['jpeg', 'jpg', 'png', 'webp'];
                    if (in_array($image_type, $allowed)) {
                        $image_base64 = base64_decode($image_parts[1]);
                        // Limit decoded size to 5MB to prevent disk exhaustion
                        if (strlen($image_base64) > 5 * 1024 * 1024) {
                            return back()->with('error', 'Photo is too large. Maximum size is 5MB.');
                        }
                        $fileName = 'attendance-photos/' . $registration->event_id . '/' . uniqid() . '.' . $image_type;
                        \Illuminate\Support\Facades\Storage::disk('s3')->put($fileName, $image_base64);
                        $photoPath = $fileName;
                    } else {
                        return back()->with('error', 'Invalid photo format. Only JPG, PNG, and WebP are allowed.');
                    }
                }
            }
        }

        Attendance::create([
            'registration_id' => $registration->id,
            'photo_path'      => $photoPath,
            'checked_in_at'   => now(),
            'status'          => 'pending',
        ]);

        return redirect()->route('student.my-events')->with('success', 'Attendance recorded! Awaiting verification.');
    }

    public function checkout(Request $request, $registrationId)
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->recordCheckout($request, $registrationId));
    }

    private function recordCheckout(Request $request, $registrationId)
    {
        $request->validate([
            'photo'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'photo_data' => ['nullable', new \App\Rules\AttendancePhoto],
        ]);

        if (!$request->hasFile('photo') && empty($request->photo_data)) {
            return back()->with('error', 'A photo is required for attendance check-out (Time Out). Please take a selfie.');
        }

        $registration = Registration::with(['attendance', 'event'])
            ->where('user_id', Auth::id())
            ->lockForUpdate()
            ->findOrFail($registrationId);

        if ($registration->status === 'cancelled' || $registration->event?->status !== 'published') {
            return back()->with('error', 'This registration or event is no longer active.');
        }
        $event = $registration->event;
        $endsAt = \Carbon\Carbon::parse($event->event_date->toDateString().' '.$event->end_time)->addHours(3);
        if (!$event->event_date->isToday() || now()->gt($endsAt)) {
            return back()->with('error', 'The attendance window has closed.');
        }

        if (!$registration->attendance) {
            return back()->with('error', 'No check-in record found for this registration.');
        }

        if ($registration->attendance->checked_out_at) {
            return back()->with('info', 'You have already checked out of this event.');
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('attendance-photos/' . $registration->event_id, 's3');
        } elseif ($request->filled('photo_data')) {
            $image_parts = explode(';base64,', $request->photo_data);
            if (count($image_parts) >= 2) {
                $image_type_aux = explode('image/', $image_parts[0]);
                if (count($image_type_aux) >= 2) {
                    $image_type = strtolower($image_type_aux[1]);
                    $allowed = ['jpeg', 'jpg', 'png', 'webp'];
                    if (in_array($image_type, $allowed)) {
                        $image_base64 = base64_decode($image_parts[1]);
                        if (strlen($image_base64) > 5 * 1024 * 1024) {
                            return back()->with('error', 'Photo is too large. Maximum size is 5MB.');
                        }
                        $fileName = 'attendance-photos/' . $registration->event_id . '/checkout_' . uniqid() . '.' . $image_type;
                        \Illuminate\Support\Facades\Storage::disk('s3')->put($fileName, $image_base64);
                        $photoPath = $fileName;
                    } else {
                        return back()->with('error', 'Invalid photo format. Only JPG, PNG, and WebP are allowed.');
                    }
                }
            }
        }

        $registration->attendance->update([
            'checkout_photo_path' => $photoPath,
            'checked_out_at'      => now(),
        ]);

        return redirect()->route('student.my-events')->with('success', 'Successfully checked out (Time Out recorded). Thank you for attending!');
    }
}
