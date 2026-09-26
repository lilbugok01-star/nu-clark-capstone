<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'approver_id',
        'role_level',
        'status',
        'comments',
        'e_signature_used',
        'proposal_reviewed_at',
        'reviewed_proposal_id',
    ];

    protected $casts = [
        'proposal_reviewed_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function reviewedProposal()
    {
        return $this->belongsTo(EventProposal::class, 'reviewed_proposal_id');
    }
}
