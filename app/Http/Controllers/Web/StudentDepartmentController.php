<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\VenueReservation;
use App\Models\VenueReservationRoom;
use App\Models\VenueReservationApproval;
use App\Models\FileHuntingSignatory;
use App\Models\User;
use App\Models\EquipmentRequest;
use App\Http\Requests\VenueReservationStoreRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StudentDepartmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!in_array(Auth::user()->role, ['student_department', 'admin'])) {
                    abort(403, 'Access denied. Authorized personnel only.');
                }
                return $next($request);
            }),
        ];
    }

    public function dashboard()
    {
        $user = Auth::user();
        $allReservations = VenueReservation::with(['event', 'approvals', 'rooms'])
            ->where('reserved_by', $user->id)
            ->orderByDesc('created_at')
            ->get();
            
        $activeReservations = $allReservations->filter(fn($r) => str_starts_with($r->status, 'pending_'));
        $historyReservations = $allReservations->filter(fn($r) => in_array($r->status, ['approved', 'rejected', 'cancelled']));

        // Fetch user's approved/published events to link reservations if needed.
        $myEvents = Event::where('organizer_id', $user->id)
            ->with('equipmentRequests')
            ->orderByDesc('event_date')
            ->get();

        // Get active file hunting chain
        $signatories = FileHuntingSignatory::where('is_active', 1)->orderBy('step_order')->get();

        return view('student_department.dashboard', compact('activeReservations', 'historyReservations', 'myEvents', 'signatories'));
    }

    public function calendarEvents()
    {
        $reservations = VenueReservation::with(['event', 'rooms'])
            ->where(function ($query) {
                $query->where('status', 'like', 'pending_%')
                      ->orWhere('status', 'approved');
            })
            ->get();

        $events = [];
        foreach ($reservations as $r) {
            $eventTitle = $r->event ? $r->event->title : ($r->event_title ?: 'Reserved');
            
            // Get all rooms description
            $roomNames = $r->rooms->pluck('room_name')->toArray();
            $roomsStr = !empty($roomNames) ? implode(', ', $roomNames) : $r->venue_name;
            
            $events[] = [
                'id'    => $r->id,
                'title' => $eventTitle . ' (' . $roomsStr . ')',
                'start' => $r->reserved_date->format('Y-m-d') . 'T' . $r->start_time,
                'end'   => $r->reserved_date->format('Y-m-d') . 'T' . $r->end_time,
                'color' => $r->status === 'approved' ? '#28a745' : '#ffc107',
            ];
        }

        return response()->json($events);
    }

    public function storeVenueReservation(VenueReservationStoreRequest $request)
    {
        $v = $request->validated();

        $finalEventId = ($v['event_id'] === 'custom') ? null : $v['event_id'];
        $finalEventTitle = ($v['event_id'] === 'custom') ? $v['event_title'] : null;

        if ($finalEventId === null && empty($finalEventTitle)) {
            return back()->with('error', 'Please provide a custom event title.')->withInput();
        }

        // Conflict check on all rooms with 1-hour buffer
        $conflict = VenueReservation::getConflict(
            $v['rooms'],
            $v['reserved_date'],
            $v['start_time'],
            $v['end_time']
        );

        if ($conflict) {
            $reservedByOrg = $conflict->reservedBy->name; // e.g. BSIT Representative
            $startFormatted = \Carbon\Carbon::parse($conflict->start_time)->format('g:i A');
            $endFormatted   = \Carbon\Carbon::parse($conflict->end_time)->format('g:i A');

            // Determine conflicting room
            $conflictingRooms = $conflict->rooms->pluck('room_name')->toArray();
            if (empty($conflictingRooms)) {
                $conflictingRooms = [$conflict->venue_name];
            }
            $intersect = array_intersect($v['rooms'], $conflictingRooms);
            $roomName = reset($intersect) ?: $conflict->venue_name;

            // Notify the existing reservation holder about the attempt/conflict
            if ($conflict->reserved_by && $conflict->reserved_by !== Auth::id()) {
                \App\Models\AppNotification::create([
                    'user_id' => $conflict->reserved_by,
                    'type'    => 'venue_reservation_conflict',
                    'title'   => "Venue Conflict Attempted: {$roomName}",
                    'message' => "Another user (" . Auth::user()->name . ") attempted to reserve {$roomName} on {$v['reserved_date']} during your reserved time slot ({$startFormatted} - {$endFormatted}).",
                ]);
            }

            return back()->with('error', "{$roomName} is already reserved by {$reservedByOrg} from {$startFormatted} to {$endFormatted}.")->withInput();
        }

        $firstSignatory = FileHuntingSignatory::where('is_active', 1)->orderBy('step_order')->first();

        // For backward compatibility save first room
        $firstRoom = $v['rooms'][0];

        $equipmentItems = collect($v['equipment_items'] ?? [])->filter(fn ($item) => trim((string) ($item['item_name'] ?? '')) !== '')->values();
        if ($equipmentItems->isEmpty() && $finalEventId) {
            $equipmentItems = Event::find($finalEventId)?->equipmentRequests
                ->map(fn ($item) => ['item_name' => $item->item_name, 'quantity' => $item->quantity, 'purpose' => $item->purpose]) ?? collect();
        }

        $reservation = \Illuminate\Support\Facades\DB::transaction(function () use ($v, $finalEventId, $finalEventTitle, $firstRoom, $firstSignatory, $equipmentItems) {
            $res = VenueReservation::create([
                'event_id'           => $finalEventId,
                'event_title'        => $finalEventTitle,
                'venue_name'         => $firstRoom,
                'reserved_date'      => $v['reserved_date'],
                'start_time'         => $v['start_time'],
                'end_time'           => $v['end_time'],
                'expected_attendees' => $v['expected_attendees'] ?? 50,
                'purpose'            => $v['purpose'] ?? null,
                'reserved_by'        => Auth::id(),
                'status'             => $firstSignatory ? 'pending_' . $firstSignatory->role : 'approved',
            ]);

            // Save each room selected
            foreach ($v['rooms'] as $room) {
                VenueReservationRoom::create([
                    'venue_reservation_id' => $res->id,
                    'room_name'            => $room,
                ]);
            }

            foreach ($equipmentItems as $item) {
                EquipmentRequest::create([
                    'venue_reservation_id' => $res->id,
                    'requested_by' => Auth::id(),
                    'item_name' => trim($item['item_name']),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'purpose' => $item['purpose'] ?? null,
                    'status' => 'requested',
                ]);
            }

            return $res;
        });

        // Log system action
        User::log('create_venue_reservation', $reservation, null, $reservation->toArray());

        return back()->with('success', 'Venue reservation request submitted for review.');
    }

    public function deleteVenueReservation($id)
    {
        $res = VenueReservation::where('reserved_by', Auth::id())->findOrFail($id);
        if (!str_starts_with($res->status, 'pending_')) {
            return back()->with('error', 'Only pending requests can be cancelled.');
        }
        
        $oldValues = $res->toArray();
        $res->status = 'cancelled';
        $res->save();

        User::log('cancel_venue_reservation', $res, $oldValues, $res->toArray());

        return back()->with('success', 'Reservation request cancelled successfully.');
    }

    public function showPermissionForm($id)
    {
        $res = VenueReservation::with(['event', 'reservedBy', 'approvals.approver', 'rooms', 'equipmentRequests.requestedBy'])->findOrFail($id);
        
        $user = Auth::user();
        if ($user->role === 'student' && $res->reserved_by !== $user->id) {
            abort(403, 'Unauthorized access to this document.');
        }

        return view('student_department.permission-form', compact('res'));
    }

    public function uploadSignature(Request $request)
    {
        $request->validate([
            'signature' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('signature')) {
            $user = Auth::user();
            
            $path = null;
            try {
                if ($user->e_signature_path) {
                    try {
                        \Illuminate\Support\Facades\Storage::disk('s3')->delete($user->e_signature_path);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($user->e_signature_path);
                    }
                }
                $path = $request->file('signature')->store('signatures', 's3');
            } catch (\Throwable $e) {
                // Keep signature images private when object storage is unavailable.
                $path = $request->file('signature')->store('signatures', 'local');
            }

            $user->update(['e_signature_path' => $path]);
            
            User::log('upload_e_signature', $user);
        }

        return back()->with('success', 'E-signature uploaded successfully!');
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
        ]);

        $date = $request->input('date');
        $startTime = $request->input('start_time');
        $endTime = $request->input('end_time');

        // Fetch all active (non-rejected, non-cancelled) reservations for this date
        $reservations = VenueReservation::with(['reservedBy', 'rooms'])
            ->where('reserved_date', $date)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->get();

        $roomsAvailability = [];
        $allRooms = VenueReservation::venueNames();

        foreach ($allRooms as $room) {
            $roomsAvailability[$room] = [
                'status' => 'available',
                'reservation' => null,
            ];
        }

        $hasTimeFilter = !empty($startTime) && !empty($endTime);
        
        if ($hasTimeFilter) {
            $startWithIngress = \Carbon\Carbon::parse($startTime)->subHour()->format('H:i');
            $endWithEgress    = \Carbon\Carbon::parse($endTime)->addHour()->format('H:i');
        }

        foreach ($reservations as $res) {
            $overlap = true;
            if ($hasTimeFilter) {
                $overlap = ($res->start_time < $endWithEgress && $res->end_time > $startWithIngress);
            }

            if ($overlap) {
                $resRooms = $res->rooms->pluck('room_name')->toArray();
                if (empty($resRooms)) {
                    $resRooms = [$res->venue_name];
                }

                foreach ($resRooms as $rName) {
                    if (isset($roomsAvailability[$rName])) {
                        $roomsAvailability[$rName] = [
                            'status' => 'occupied',
                            'reservation' => [
                                'id' => $res->id,
                                'event_title' => $res->event?->title ?? $res->event_title ?? 'Untitled Event',
                                'reserved_by' => $res->reservedBy->full_name ?? 'Unknown',
                                'start_time' => \Carbon\Carbon::parse($res->start_time)->format('g:i A'),
                                'end_time' => \Carbon\Carbon::parse($res->end_time)->format('g:i A'),
                            ]
                        ];
                    }
                }
            }
        }

        return response()->json($roomsAvailability);
    }
}
