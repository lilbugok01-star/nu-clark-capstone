<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VenueReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'event_title', 'reserved_by', 'venue_name',
        'reserved_date', 'start_time', 'end_time',
        'expected_attendees', 'status', 'purpose', 'notes',
        'override_by', 'override_at', 'override_reason',
    ];

    protected $casts = [
        'reserved_date' => 'date',
        'override_at'   => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function reservedBy()
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function overriddenBy()
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    public function approvals()
    {
        return $this->hasMany(VenueReservationApproval::class);
    }

    public function rooms()
    {
        return $this->hasMany(VenueReservationRoom::class);
    }

    public function equipmentRequests()
    {
        return $this->hasMany(EquipmentRequest::class);
    }

    public static function venueNames(): array
    {
        $venues = [
            'NU Clark Gymnasium',
            'NU Clark Auditorium',
            'NU Clark Library',
            'Mini Chapel',
        ];

        // Systematically add classrooms (4th to 8th Floor)
        foreach ([4, 5, 6, 7, 8] as $floor) {
            for ($i = 1; $i <= 23; $i++) {
                $venues[] = 'Room ' . $floor . sprintf('%02d', $i);
            }
        }
        
        return $venues;
    }

    public static function getConflict(array $rooms, string $date, string $startTime, string $endTime, ?int $excludeId = null): ?self
    {
        return self::getConflicts($rooms, $date, $startTime, $endTime, $excludeId)->first();
    }

    public static function getConflicts(array $rooms, string $date, string $startTime, string $endTime, ?int $excludeId = null)
    {
        // Parse time with Carbon to construct buffers
        $startWithIngress = \Carbon\Carbon::parse($startTime)->subHour()->format('H:i');
        $endWithEgress    = \Carbon\Carbon::parse($endTime)->addHour()->format('H:i');

        return self::with(['reservedBy', 'rooms'])
            ->where('reserved_date', $date)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where(function ($query) use ($excludeId) {
                if ($excludeId) {
                    $query->where('id', '!=', $excludeId);
                }
            })
            ->where(function ($query) use ($rooms) {
                $query->whereHas('rooms', function ($q) use ($rooms) {
                    $q->whereIn('room_name', $rooms);
                })
                ->orWhereIn('venue_name', $rooms);
            })
            ->where(function ($q) use ($startWithIngress, $endWithEgress) {
                $q->where('start_time', '<', $endWithEgress)
                  ->where('end_time', '>', $startWithIngress);
            })
            ->get();
    }
}
