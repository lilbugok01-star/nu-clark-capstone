<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentRequest extends Model
{
    protected $fillable = [
        'event_id', 'venue_reservation_id', 'requested_by', 'item_name',
        'quantity', 'purpose', 'status', 'notes',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function venueReservation()
    {
        return $this->belongsTo(VenueReservation::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
