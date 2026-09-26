<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'venue', 'venue_type', 'event_date',
        'start_time', 'end_time', 'capacity', 'organizer_id',
        'poster_path', 'is_featured', 'category', 'tags', 'status',
    ];

    protected $casts = [
        'event_date'  => 'date',
        'is_featured' => 'boolean',
    ];

    // Relationships
    public function organizer()         { return $this->belongsTo(User::class, 'organizer_id'); }
    public function registrations()     { return $this->hasMany(Registration::class); }
    public function attendees()         { return $this->belongsToMany(User::class, 'registrations'); }
    public function attendances()       { return $this->hasManyThrough(Attendance::class, Registration::class); }
    public function venueReservation()  { return $this->hasOne(VenueReservation::class); }
    public function approvals()         { return $this->hasMany(EventApproval::class); }
    public function budgetItems()       { return $this->hasMany(EventBudget::class); }
    public function budgets()           { return $this->hasMany(EventBudget::class); }
    public function payments()          { return $this->hasMany(EventPayment::class); }
    public function proposals()         { return $this->hasMany(EventProposal::class); }
    public function equipmentRequests() { return $this->hasMany(EquipmentRequest::class); }
    public function approvedProposal()  { return $this->hasOne(EventProposal::class)->where('status', 'approved')->latestOfMany(); }
    public function latestProposal()    { return $this->hasOne(EventProposal::class)->latestOfMany(); }

    public function isLive(): bool
    {
        $now = now();
        
        if ($this->event_date->toDateString() !== $now->toDateString() || $this->status !== 'published') {
            return false;
        }

        $startTime = \Carbon\Carbon::parse($this->event_date->toDateString() . ' ' . $this->start_time);
        $endTime   = \Carbon\Carbon::parse($this->event_date->toDateString() . ' ' . $this->end_time);

        return $now->between($startTime, $endTime);
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>=', now()->toDateString())
                     ->where('status', 'published')
                     ->orderBy('event_date');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeSearch($query, $keyword)
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
        return $query->where(function($q) use ($escaped) {
            $q->where('title', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%")
              ->orWhere('venue', 'like', "%{$escaped}%")
              ->orWhere('category', 'like', "%{$escaped}%");
        });
    }

    // Helpers
    public function isFull(): bool
    {
        return $this->registrations()->where('status', '!=', 'cancelled')->count() >= $this->capacity;
    }

    public function registeredCount(): int
    {
        return $this->registrations()->where('status', '!=', 'cancelled')->count();
    }

    public function attendedCount(): int
    {
        return $this->registrations()->whereHas('attendance', fn($q) => $q->where('status', 'verified'))->count();
    }

    public function hasCompleteApprovalEvidence(): bool
    {
        $requiredRoles = ['adviser', 'department_head', 'dean', 'executive_director'];
        $proposal = $this->approvedProposal()->first();

        return $proposal !== null
            && $this->approvals()->where('status', 'approved')
                ->where('reviewed_proposal_id', $proposal->id)
                ->whereNotNull('proposal_reviewed_at')
                ->whereIn('role_level', $requiredRoles)
                ->distinct()->count('role_level') === count($requiredRoles);
    }
}
