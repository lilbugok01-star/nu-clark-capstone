<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\AttendanceExport;
use App\Exports\EventReportExport;

class ReportController extends Controller
{
    public function events(Request $request)
    {
        $events = Event::with('organizer')
            ->when(!$request->user()->isAdmin(), fn ($query) => $query->where('organizer_id', $request->user()->id))
            ->withCount([
                'registrations as registrations_count' => fn($q) => $q->where('status', '!=', 'cancelled'),
                'registrations as verified_attendance_count' => fn($q) => $q->whereHas('attendance', fn($aq) => $aq->where('status', 'verified'))
            ])
            ->orderByDesc('event_date')
            ->paginate(20);

        return response()->json($events);
    }

    public function attendance($eventId)
    {
        $event = Event::findOrFail($eventId);
        Gate::authorize('update', $event);

        $attendances = Attendance::with(['registration.user.course', 'registration.user.section', 'verifiedBy'])
            ->whereHas('registration', fn($q) => $q->where('event_id', $eventId))
            ->get();

        $total      = Registration::where('event_id', $eventId)->where('status', '!=', 'cancelled')->count();
        $attended   = $attendances->count();
        $verified   = $attendances->where('status', 'verified')->count();

        return response()->json([
            'event'      => $event,
            'total_registered' => $total,
            'total_attended'   => $attended,
            'total_verified'   => $verified,
            'rate'             => $total > 0 ? round(($attended / $total) * 100, 1) . '%' : '0%',
            'attendances'      => $attendances,
        ]);
    }

    public function student($studentId)
    {
        $student = User::with('course', 'section')->findOrFail($studentId);

        $registrations = Registration::with(['event', 'attendance'])
            ->where('user_id', $studentId)
            ->orderByDesc('registered_at')
            ->get();

        $attended = $registrations->filter(fn($r) => $r->attendance && $r->attendance->status === 'verified')->count();

        return response()->json([
            'student'              => $student,
            'total_registrations'  => $registrations->count(),
            'events_attended'      => $attended,
            'participation_rate'   => $registrations->count() > 0
                ? round(($attended / $registrations->count()) * 100, 1) . '%'
                : '0%',
            'registrations'        => $registrations,
        ]);
    }

    public function exportAttendancePdf($eventId)
    {
        $event = Event::with('organizer')->findOrFail($eventId);
        Gate::authorize('update', $event);
        $attendances = Attendance::with(['registration.user.course', 'registration.user.section'])
            ->whereHas('registration', fn($q) => $q->where('event_id', $eventId))
            ->get();

        $pdf = Pdf::loadView('reports.attendance-pdf', compact('event', 'attendances'))
                  ->setPaper('a4', 'portrait');

        return $pdf->download("attendance-{$event->id}.pdf");
    }

    public function exportAttendanceExcel($eventId)
    {
        $event = Event::findOrFail($eventId);
        Gate::authorize('update', $event);
        return Excel::download(new AttendanceExport($eventId), "attendance-{$event->title}-{$eventId}.xlsx");
    }

    public function exportEventsPdf()
    {
        $events = Event::with('organizer')
            ->withCount([
                'registrations as registrations_count' => fn($q) => $q->where('status', '!=', 'cancelled'),
                'registrations as verified_count' => fn($q) => $q->whereHas('attendance', fn($aq) => $aq->where('status', 'verified'))
            ])
            ->orderByDesc('event_date')->get();
        $pdf = Pdf::loadView('reports.events-pdf', compact('events'))->setPaper('a4', 'landscape');
        return $pdf->download('events-report.pdf');
    }
}
