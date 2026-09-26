<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventProposal extends Model
{
    protected $fillable = [
        'event_id', 'prepared_by', 'proposal_number', 'status',
        'event_overview', 'objectives', 'target_audience',
        'estimated_budget', 'venue_details', 'schedule_details',
        'requirements', 'expected_outcomes', 'recommendations', 'recommendations_applied_at',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected $casts = [
        'estimated_budget' => 'decimal:2',
        'approved_at'      => 'datetime',
        'recommendations_applied_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Generate unique proposal number: PROP-YYYYMMDD-XXXX
     */
    public static function generateNumber(): string
    {
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        return 'PROP-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
