<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\Section;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\AppNotification;
use App\Models\VenueReservation;
use App\Models\VenueReservationApproval;
use App\Models\FileHuntingSignatory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!Auth::check() || Auth::user()->role !== 'admin') {
                    abort(403, 'Access denied. Admin only.');
                }
                return $next($request);
            }),
        ];
    }


    public function dashboard()
    {
        // Auto-populate all organizers, students, courses, and demo events if database was fresh
        try {
            if (User::where('role', 'organizer')->count() === 0 || User::where('role', 'student')->count() === 0) {
                (new \Database\Seeders\DatabaseSeeder())->run();
            }
        } catch (\Throwable $e) {
            \Log::warning('Dashboard auto-seed check: ' . $e->getMessage());
        }

        $stats = [
            'total_students'      => User::where('role', 'student')->count(),
            'total_organizers'    => User::where('role', 'organizer')->count(),
            'total_events'        => Event::count(),
            'upcoming_events'     => Event::upcoming()->count(),
            'total_registrations' => Registration::where('status', '!=', 'cancelled')->count(),
            'total_attendances'   => Attendance::where('status', 'verified')->count(),
        ];

        $recentUsers    = User::latest()->take(5)->get();
        $upcomingEvents = Event::with('organizer')->upcoming()->take(5)->get();
        $recentEvents   = $upcomingEvents->isNotEmpty()
            ? $upcomingEvents
            : Event::with('organizer')->latest()->take(5)->get();

        // Monthly registrations for chart
        $monthlyData = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $monthlyData[] = [
                'month' => $m->format('M'),
                'count' => Registration::whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count(),
            ];
        }

        return view('admin.dashboard', compact('stats', 'recentUsers', 'recentEvents', 'upcomingEvents', 'monthlyData'));
    }

    public function auditLogs(Request $request)
    {
        $sysQuery = \App\Models\SystemAuditLog::with('user')->orderByDesc('created_at');
        
        if ($request->filled('action')) {
            $escapedAction = str_replace(['%', '_'], ['\\%', '\\_'], $request->action);
            $sysQuery->where('action', 'like', '%' . $escapedAction . '%');
        }
        if ($request->filled('user_id')) {
            $sysQuery->where('user_id', $request->user_id);
        }
        if ($request->filled('date_start')) {
            $sysQuery->whereDate('created_at', '>=', $request->date_start);
        }
        if ($request->filled('date_end')) {
            $sysQuery->whereDate('created_at', '<=', $request->date_end);
        }
        
        $systemLogs = $sysQuery->paginate(25, ['*'], 'sys_page')->withQueryString();

        $attQuery = \App\Models\AttendanceAuditLog::with(['user', 'event', 'registration'])->orderByDesc('created_at');
        
        if ($request->filled('status')) {
            $attQuery->where('status', $request->status);
        }
        if ($request->filled('att_user_id')) {
            $attQuery->where('user_id', $request->att_user_id);
        }
        
        $attendanceLogs = $attQuery->paginate(25, ['*'], 'att_page')->withQueryString();
        
        $users = User::orderBy('surname')->orderBy('first_name')->get();

        return view('admin.audit-logs', compact('systemLogs', 'attendanceLogs', 'users'));
    }

    public function users(Request $request)
    {
        $query = User::with('course', 'section');
        if ($request->role) $query->where('role', $request->role);
        
        if ($request->search) {
            $search = trim($request->search);
            // Replace percent and underscore to prevent LIKE wildcards abuse
            $search = str_replace(['%', '_'], ['\%', '\_'], $search);
            $query->where(fn($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('middle_name', 'like', "%{$search}%")->orWhere('surname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        
        $users   = $query->paginate(20);
        $courses = Course::where('is_active', true)->get();
        $sections= Section::where('is_active', true)->with('course')->get();
        return view('admin.users', compact('users', 'courses', 'sections'));
    }

    public function storeUser(Request $request)
    {
        $allRoles = 'admin,organizer,student,adviser,department_head,dean,executive_director,student_development,program_chair,student_department';

        $rules = [
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'surname'     => 'required|string|max:255',
            'email'      => 'required|email|unique:users',
            'password'   => [
                'required',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'role'       => 'required|in:' . $allRoles,
            'student_id' => 'nullable|string|unique:users,student_id|regex:/^\d{4}-\d{6}$/',
            'course_id'  => 'nullable|exists:courses,id',
            'section_id' => 'nullable|exists:sections,id',
            'e_signature'=> 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];

        if ($request->role === 'student') {
            $rules['email'] = [
                'required', 'email', 'unique:users',
                function ($attribute, $value, $fail) {
                    if (!str_ends_with(strtolower($value), '@students.nu-clark.edu.ph')) {
                        $fail('Student accounts must use an official NU Clark email (@students.nu-clark.edu.ph).');
                    }
                },
            ];
            $rules['student_id'] = 'required|string|unique:users,student_id|regex:/^\d{4}-\d{6}$/';
            $rules['course_id']  = 'required|exists:courses,id';
            $rules['section_id'] = 'required|exists:sections,id';
        }

        $v = $request->validate($rules, [
            'student_id.regex' => 'The Student ID format must be YYYY-NNNNNN (e.g. 2023-190866).',
        ]);
        $v['password'] = Hash::make($v['password']);

        // Handle optional e-signature upload
        if ($request->hasFile('e_signature')) {
            $v['e_signature_path'] = $request->file('e_signature')->store('signatures', 's3');
        }

        $user = User::create($v);
        User::log('create_user', $user, null, $user->toArray());
        return back()->with('success', 'User created successfully!');
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $oldValues = $user->toArray();
        $allRoles = 'admin,organizer,student,adviser,department_head,dean,executive_director,student_development,program_chair,student_department';

        $rules = [
            'first_name'  => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'surname'     => 'sometimes|string|max:255',
            'role'      => 'sometimes|in:' . $allRoles,
            'is_active' => 'sometimes|boolean',
        ];

        $role = $request->input('role', $user->role);

        // Only validate student-specific fields if the role is student
        // AND those fields were actually submitted (so simple deactivation works)
        if ($role === 'student' && $request->has('student_id')) {
            $rules['student_id'] = 'required|string|regex:/^\d{4}-\d{6}$/|unique:users,student_id,' . $id;
            $rules['course_id']  = 'required|exists:courses,id';
            $rules['section_id'] = 'required|exists:sections,id';
        } elseif ($role !== 'student') {
            // Clear student fields when switching away from student role
            $request->merge(['student_id' => null, 'course_id' => null, 'section_id' => null]);
            $rules['student_id'] = 'nullable';
            $rules['course_id']  = 'nullable';
            $rules['section_id'] = 'nullable';
        }

        $v = $request->validate($rules, [
            'student_id.regex' => 'The Student ID format must be YYYY-NNNNNN (e.g. 2023-190866).',
        ]);

        // Track if role changed
        $oldRole = $user->role;

        $user->update($v);

        User::log('update_user', $user, $oldValues, $user->toArray());

        // If role changed, force the user to re-login so they see their new dashboard
        if (isset($v['role']) && $v['role'] !== $oldRole) {
            // Invalidate remember token to force re-authentication
            $user->update(['remember_token' => null]);

            // If the admin is editing themselves, don't log themselves out
            if ((int)$id !== Auth::id()) {
                // Clear all sessions for this user by cycling their password hash
                // This is a lightweight approach — the user will need to re-login
                \Illuminate\Support\Facades\DB::table('sessions')
                    ->where('user_id', $id)
                    ->delete();
            }
        }

        return back()->with('success', 'User updated!');
    }

    public function deleteUser($id)
    {
        if ((int)$id === Auth::id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        $user = User::findOrFail($id);
        $oldValues = $user->toArray();
        $user->delete();
        User::log('delete_user', $user, $oldValues, null);
        return back()->with('success', 'User deleted.');
    }

    public function verifyUserEmail($id)
    {
        $user = User::findOrFail($id);
        $user->forceFill([
            'email_verified_at'       => now(),
            'email_verification_code' => null,
        ])->save();

        User::log('admin_verify_user_email', $user);

        return back()->with('success', "Email for {$user->full_name} ({$user->email}) has been verified by Admin!");
    }

    public function courses()
    {
        $courses = Course::with('sections')->get();
        return view('admin.courses', compact('courses'));
    }

    public function storeCourse(Request $request)
    {
        $v = $request->validate(['code' => 'required|unique:courses', 'name' => 'required']);
        $course = Course::create($v);
        User::log('create_course', $course, null, $course->toArray());

        // Auto-generate sections for the new course
        $prefix = preg_replace('/[^A-Z]/', '', strtoupper($course->code));
        if (strlen($prefix) > 3) {
            $prefix = substr($prefix, -3);
        } elseif (strlen($prefix) == 0) {
            $prefix = 'SEC';
        }

        for ($year = 1; $year <= 4; $year++) {
            for ($sec = 1; $sec <= 5; $sec++) {
                $sectionName = $prefix . '-' . $year . str_pad($sec, 2, '0', STR_PAD_LEFT);
                Section::create([
                    'course_id' => $course->id,
                    'name' => $sectionName,
                    'year_level' => $year,
                    'is_active' => true
                ]);
            }
        }

        return back()->with('success', 'Course added successfully along with its sections!');
    }

    protected function buildReportEventsQuery(Request $request)
    {
        $query = Event::with('organizer');

        if ($request->filled('organizer_id')) {
            $query->where('organizer_id', $request->organizer_id);
        }
        if ($request->filled('date_start')) {
            $query->whereDate('event_date', '>=', $request->date_start);
        }
        if ($request->filled('date_end')) {
            $query->whereDate('event_date', '<=', $request->date_end);
        }
        if ($request->filled('course_id')) {
            $query->whereHas('registrations.user', function($q) use ($request) {
                $q->where('course_id', $request->course_id);
            });
        }
        if ($request->filled('section_id')) {
            $query->whereHas('registrations.user', function($q) use ($request) {
                $q->where('section_id', $request->section_id);
            });
        }

        $query->withCount([
            'registrations as registrations_count' => function($q) use ($request) {
                $q->where('status', '!=', 'cancelled');
                if ($request->filled('course_id')) {
                    $q->whereHas('user', fn($uq) => $uq->where('course_id', $request->course_id));
                }
                if ($request->filled('section_id')) {
                    $q->whereHas('user', fn($uq) => $uq->where('section_id', $request->section_id));
                }
            },
            'registrations as verified_count' => function($q) use ($request) {
                $q->whereHas('attendance', fn($aq) => $aq->where('status', 'verified'));
                if ($request->filled('course_id')) {
                    $q->whereHas('user', fn($uq) => $uq->where('course_id', $request->course_id));
                }
                if ($request->filled('section_id')) {
                    $q->whereHas('user', fn($uq) => $uq->where('section_id', $request->section_id));
                }
            }
        ]);

        return $query;
    }

    public function reports(Request $request)
    {
        $events = $this->buildReportEventsQuery($request)->orderByDesc('event_date')->paginate(15)->withQueryString();

        $organizers = User::whereIn('role', ['organizer', 'student_development', 'admin'])->orderBy('first_name')->orderBy('surname')->get();
        $courses = Course::where('is_active', true)->orderBy('code')->get();
        $sections = Section::where('is_active', true)->orderBy('name')->get();

        return view('admin.reports', compact('events', 'organizers', 'courses', 'sections'));
    }

    public function exportEventsPdf(Request $request)
    {
        $events = $this->buildReportEventsQuery($request)->orderByDesc('event_date')->get();

        User::log('export_events_pdf', null, null, [
            'format' => 'pdf',
            'filters' => $request->only(['organizer_id', 'date_start', 'date_end', 'course_id', 'section_id'])
        ]);

        $pdf = Pdf::loadView('reports.events-pdf', compact('events'))->setPaper('a4', 'landscape');
        return $pdf->download('nu-clark-events-report.pdf');
    }

    public function notifications()
    {
        $users = User::select('id', 'first_name', 'middle_name', 'surname', 'email', 'role')->orderBy('first_name')->orderBy('surname')->get();
        return view('admin.notifications', compact('users'));
    }

    public function sendNotification(Request $request)
    {
        $v = $request->validate([
            'role'    => 'nullable|in:admin,organizer,student,adviser,department_head,dean,executive_director,student_development,program_chair,student_department',
            'title'   => 'required|string|max:255',
            'message' => 'required|string|max:2000',
        ]);

        $query = User::query();
        if ($v['role']) $query->where('role', $v['role']);
        $userIds = $query->pluck('id');

        $notifs = $userIds->map(fn($uid) => [
            'user_id'    => $uid,
            'type'       => 'admin_broadcast',
            'title'      => $v['title'],
            'message'    => $v['message'],
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        AppNotification::insert($notifs);

        User::log('send_broadcast_notification', null, null, ['title' => $v['title'], 'role' => $v['role']]);

        return back()->with('success', "Notification sent to {$userIds->count()} users.");
    }

    // ─── Event Management (Admin) ────────────────
    public function events(Request $request)
    {
        $tab = $request->query('tab', 'upcoming');
        $query = Event::with('organizer')->withCount([
            'registrations as registrations_count' => fn($q) => $q->where('status', '!=', 'cancelled'),
            'registrations as verified_count' => fn($q) => $q->whereHas('attendance', fn($aq) => $aq->where('status', 'verified'))
        ]);

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->search);
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('venue', 'like', '%' . $search . '%')
                  ->orWhereHas('organizer', fn($oq) => $oq->where('first_name', 'like', '%' . $search . '%')->orWhere('surname', 'like', '%' . $search . '%'));
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('organizer_id')) {
            $query->where('organizer_id', $request->organizer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $today = now()->toDateString();
        if ($tab === 'past') {
            $query->where(function($q) use ($today) {
                $q->where('event_date', '<', $today)
                  ->orWhereIn('status', ['completed', 'cancelled', 'rejected']);
            })->orderByDesc('event_date');
        } else {
            $query->where('event_date', '>=', $today)
                  ->whereNotIn('status', ['cancelled', 'rejected'])
                  ->orderBy('event_date', 'asc')
                  ->orderBy('start_time', 'asc');
        }

        $events = $query->paginate(15)->withQueryString();

        $upcomingCount = Event::where('event_date', '>=', $today)
            ->whereNotIn('status', ['cancelled', 'rejected'])->count();
        $pastCount = Event::where(function($q) use ($today) {
            $q->where('event_date', '<', $today)
              ->orWhereIn('status', ['completed', 'cancelled', 'rejected']);
        })->count();

        $organizers = User::whereIn('role', ['organizer', 'student_development', 'admin'])->orderBy('first_name')->get();
        $categories = Event::whereNotNull('category')->distinct()->pluck('category');

        return view('admin.events', compact('events', 'tab', 'upcomingCount', 'pastCount', 'organizers', 'categories'));
    }

    public function deleteEvent(Request $request, $id)
    {
        $event = Event::with('registrations.user')->findOrFail($id);
        $eventTitle = $event->title;
        $registeredUsers = $event->registrations->pluck('user_id')->filter()->unique();

        // 1. Cancel all registrations for this event so it immediately clears from student dashboards
        $event->registrations()->where('status', '!=', 'cancelled')->update(['status' => 'cancelled']);

        // 2. Notify all registered students immediately
        if ($registeredUsers->isNotEmpty()) {
            $notifs = $registeredUsers->map(fn($uid) => [
                'user_id'    => $uid,
                'type'       => 'event_cancelled',
                'title'      => 'Event Cancelled: ' . $eventTitle,
                'message'    => "The event '{$eventTitle}' scheduled for " . ($event->event_date ? $event->event_date->format('M d, Y') : '') . " has been removed by the administration. Your registration has been cancelled.",
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();
            AppNotification::insert($notifs);
        }

        // 3. Log the system audit
        User::log('admin_delete_event', $event, $event->toArray(), [
            'action' => 'deleted_by_admin',
            'reason' => $request->input('reason', 'Administrative removal')
        ]);

        // 4. Soft delete the event
        $event->delete();

        return back()->with('success', "Event '{$eventTitle}' was successfully removed and all registered students have been notified.");
    }

    public function updateEventStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:draft,published,cancelled,completed',
        ]);

        $event = Event::findOrFail($id);
        if ($request->status === 'published' && !$event->hasCompleteApprovalEvidence()) {
            return back()->with('error', 'The event cannot be published until an approved proposal is attached and all required signatories have approved it.');
        }
        $old = $event->toArray();
        $event->update(['status' => $request->status]);

        User::log('admin_update_event_status', $event, $old, $event->toArray());

        return back()->with('success', "Status for '{$event->title}' updated to " . ucfirst($request->status) . ".");
    }

    // ─── Venue Management (Admin) ────────────────
    public function venues(Request $request)
    {
        $query = VenueReservation::with(['event', 'reservedBy', 'rooms', 'approvals'])->orderByDesc('created_at');
        if ($request->status && $request->status !== 'all') {
            if ($request->status === 'pending') {
                $query->where('status', 'like', 'pending_%');
            } else {
                $query->where('status', $request->status);
            }
        }
        $reservations = $query->paginate(20)->withQueryString();
        $total    = VenueReservation::count();
        $pending  = VenueReservation::where('status', 'like', 'pending_%')->count();
        $approved = VenueReservation::where('status', 'approved')->count();
        $rejected = VenueReservation::where('status', 'rejected')->count();
        return view('admin.venues', compact('reservations','total','pending','approved','rejected'));
    }

    public function updateVenueStatus(Request $request, $id)
    {
        $res = VenueReservation::findOrFail($id);
        $oldValues = $res->toArray();
        $v = $request->validate(['status' => 'required|in:approved,rejected', 'notes' => 'nullable|string']);
        $res->update(['status' => $v['status'], 'notes' => $v['notes'] ?? null]);
        $msg = $v['status'] === 'approved' ? 'approved ✓' : 'rejected ✗';
        $safeNotes = strip_tags($v['notes'] ?? '');
        
        User::log('update_venue_status', $res, $oldValues, $res->toArray());

        // Notify organizer
        AppNotification::create([
            'user_id' => $res->reserved_by,
            'type'    => 'venue_reservation',
            'title'   => "Venue Reservation {$msg}: {$res->venue_name}",
            'message' => $safeNotes ? "Your reservation for {$res->venue_name} on {$res->reserved_date->format('M d')} has been {$v['status']}. Notes: {$safeNotes}" : "Your reservation for {$res->venue_name} on {$res->reserved_date->format('M d')} has been {$v['status']}.",
        ]);
        return back()->with('success', "Venue reservation {$msg}!");
    }

    public function deleteVenue($id)
    {
        $res = VenueReservation::findOrFail($id);
        $oldValues = $res->toArray();
        $res->delete();
        User::log('delete_venue_reservation', $res, $oldValues, null);
        return back()->with('success', 'Venue reservation deleted.');
    }

    public function overrideVenue(Request $request, $id)
    {
        $res = VenueReservation::findOrFail($id);
        $validated = $request->validate([
            'override_reason' => 'required|string|min:5|max:500',
        ]);

        $oldValues = $res->toArray();

        \Illuminate\Support\Facades\DB::transaction(function () use ($res, $validated) {
            $res->update([
                'status'          => 'approved',
                'override_by'     => Auth::id(),
                'override_at'     => now(),
                'override_reason' => $validated['override_reason'],
            ]);

            // Auto-approve all signatories via override
            $activeSignatories = FileHuntingSignatory::where('is_active', 1)->get();
            foreach ($activeSignatories as $sig) {
                VenueReservationApproval::updateOrCreate(
                    [
                        'venue_reservation_id' => $res->id,
                        'role_level'           => $sig->role,
                    ],
                    [
                        'approver_id'          => Auth::id(),
                        'status'               => 'approved',
                        'comments'             => 'Approved via Admin Override: ' . $validated['override_reason'],
                        'opened_at'            => now(),
                    ]
                );
            }
        });

        // Log system action
        User::log('override_venue_reservation', $res, $oldValues, $res->fresh()->toArray());

        // Notify user
        AppNotification::create([
            'user_id' => $res->reserved_by,
            'type'    => 'venue_reservation',
            'title'   => "Venue Reservation Approved by Admin Override",
            'message' => "Your reservation for " . $res->venue_name . " has been force-approved by the administrator. Reason: " . $validated['override_reason'],
        ]);

        return back()->with('success', 'Venue reservation approved via admin override.');
    }

    // ─── File Hunting Module ─────────────────────

    public function fileHunting()
    {
        $signatories = FileHuntingSignatory::orderBy('step_order')->get();
        $availableRoles = [
            'student_development' => 'Student Development Officer',
            'program_chair'       => 'Program Chair',
            'dean'                => 'College Dean',
            'executive_director'  => 'Executive Director',
            'adviser'             => 'Adviser',
            'department_head'     => 'Department Head',
        ];
        return view('admin.file-hunting', compact('signatories', 'availableRoles'));
    }

    public function saveSignatories(Request $request)
    {
        $request->validate([
            'signatories'                  => 'required|array|min:1',
            'signatories.*.role'           => 'required|string|distinct|in:executive_director,program_chair,dean,student_development,adviser,department_head',
            'signatories.*.position_label' => 'required|string|max:100',
            'signatories.*.is_active'      => 'sometimes|boolean',
        ]);

        // Wipe existing and re-insert in order — wrapped in transaction to prevent data loss
        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            FileHuntingSignatory::query()->delete();

            foreach ($request->signatories as $i => $sig) {
                FileHuntingSignatory::create([
                    'step_order'     => $i + 1,
                    'role'           => $sig['role'],
                    'position_label' => $sig['position_label'],
                    'is_active'      => (bool) ($sig['is_active'] ?? false),
                ]);
            }
        });

        User::log('update_signatories_chain', null, null, $request->signatories);

        return back()->with('success', 'Signing chain updated successfully.');
    }
}
