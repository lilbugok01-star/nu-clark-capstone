<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index()
    {
        // Redirect unverified students to the verification page
        if (Auth::check()) {
            $user = Auth::user();
            if ($user->isStudent() && is_null($user->email_verified_at)) {
                return redirect()->route('verification.notice');
            }
        }

        // All queries are done inline in welcome.blade.php using @php
        return view('welcome');
    }

    public function events(Request $request)
    {
        $query = Event::with('organizer')->published();

        if ($request->search)   $query->search($request->search);
        if ($request->category) $query->where('category', $request->category);
        if ($request->date)     $query->whereDate('event_date', $request->date);

        $events     = $query->orderBy('event_date')->paginate(12);
        $categories = Event::published()->whereNotNull('category')->distinct()->pluck('category');

        return view('home.events', compact('events', 'categories'));
    }

    /**
     * Shared JSON feed for FullCalendar — published events + venue reservations.
     */
    public function calendarEventsJson()
    {
        $items = [];

        // Published events
        $categoryColors = [
            'IT'          => '#0284c7',
            'Business'    => '#f59e0b',
            'Nursing'     => '#10b981',
            'Engineering' => '#8b5cf6',
            'Academic'    => '#6366f1',
            'Sports'      => '#ef4444',
            'Cultural'    => '#ec4899',
        ];

        $events = Event::where('status', 'published')->get();
        foreach ($events as $e) {
            $color = $categoryColors[$e->category] ?? '#003087';
            $items[] = [
                'id'    => 'event-' . $e->id,
                'title' => $e->title,
                'start' => $e->event_date->format('Y-m-d') . 'T' . $e->start_time,
                'end'   => $e->event_date->format('Y-m-d') . 'T' . $e->end_time,
                'color' => $color,
                'extendedProps' => [
                    'type'     => 'event',
                    'venue'    => $e->venue,
                    'category' => $e->category,
                    'status'   => $e->status,
                ],
            ];
        }

        // Venue reservations (approved only)
        $reservations = \App\Models\VenueReservation::with('event')
            ->where('status', 'approved')
            ->get();

        foreach ($reservations as $r) {
            $eventTitle = $r->event ? $r->event->title : ($r->event_title ?: 'Reserved');
            $items[] = [
                'id'    => 'venue-' . $r->id,
                'title' => $eventTitle . ' (' . $r->venue_name . ')',
                'start' => $r->reserved_date->format('Y-m-d') . 'T' . $r->start_time,
                'end'   => $r->reserved_date->format('Y-m-d') . 'T' . $r->end_time,
                'color' => $r->status === 'approved' ? '#28a745' : '#ffc107',
                'extendedProps' => [
                    'type'   => 'venue',
                    'venue'  => $r->venue_name,
                    'status' => $r->status,
                ],
            ];
        }

        return response()->json($items);
    }

    public function showEvent($id)
    {
        $event = Event::with('organizer')->whereIn('status', ['published', 'completed'])->findOrFail($id);
        $event->registered_count = $event->registeredCount();
        $event->attended_count   = $event->attendedCount();
        $event->is_full          = $event->isFull();

        $isRegistered = false;
        if (Auth::check()) {
            $isRegistered = Registration::where('user_id', Auth::id())
                ->where('event_id', $id)
                ->where('status', '!=', 'cancelled')->exists();
        }

        return view('home.event-detail', compact('event', 'isRegistered'));
    }
}
