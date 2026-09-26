@extends('layouts.app')

@section('title', 'View Event Proposal')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center">
        <h2 class="h3 mb-0 text-gray-800 me-3">Proposal Details</h2>
        @php
            $badgeClass = match($proposal->status) {
                'draft' => 'bg-secondary',
                'submitted' => 'bg-primary',
                'approved' => 'bg-success',
                'rejected' => 'bg-danger',
                default => 'bg-secondary',
            };
        @endphp
        <span class="badge {{ $badgeClass }} fs-6 rounded-pill px-3 py-2">{{ ucfirst($proposal->status) }}</span>
    </div>
    
    <div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <a href="{{ route('proposal.export-pdf', $proposal->id) }}" class="btn btn-outline-danger">
            <i class="bi bi-file-earmark-pdf"></i> Export PDF
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<!-- Action Bar -->
@if(in_array($proposal->status, ['draft', 'rejected'], true) && (Auth::id() === $proposal->prepared_by || Auth::user()->role === 'admin'))
    <div class="card mb-4 border-primary shadow-sm bg-primary bg-opacity-10">
        <div class="card-body d-flex justify-content-between align-items-center py-3">
            <div>
                <h6 class="mb-1 fw-bold text-primary"><i class="bi bi-info-circle me-2"></i>{{ $proposal->status === 'rejected' ? 'Revision Required' : 'Draft Proposal' }}</h6>
                <p class="mb-0 small text-muted">Edit the proposal as needed, then submit the current version for review.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('proposal.edit', $proposal) }}" class="btn btn-outline-primary fw-bold"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                @if($proposal->status === 'draft')
                    <form action="{{ route('proposal.submit', $proposal->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary fw-bold" onclick="return confirm('Submit this proposal for review?')"><i class="bi bi-send me-1"></i>Submit for Review</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@elseif($proposal->status === 'submitted' && Auth::user()->role === 'admin')
    <div class="card mb-4 border-warning shadow-sm bg-warning bg-opacity-10">
        <div class="card-body d-flex justify-content-between align-items-center py-3">
            <div>
                <h6 class="mb-1 fw-bold text-dark"><i class="bi bi-exclamation-circle me-2"></i>Pending Review</h6>
                <p class="mb-0 small text-muted">This proposal has been submitted and is awaiting your review.</p>
            </div>
            <div class="d-flex gap-2">
                <form action="{{ route('proposal.approve', $proposal->id) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success fw-bold" onclick="return confirm('Approve this proposal?')">
                        <i class="bi bi-check-circle me-1"></i> Approve
                    </button>
                </form>
                <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="bi bi-x-circle me-1"></i> Reject
                </button>
            </div>
        </div>
    </div>
@endif

@if($proposal->status === 'rejected')
    <div class="alert alert-danger border-danger">
        <h6 class="alert-heading fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Proposal Rejected</h6>
        <hr>
        <p class="mb-0"><strong>Reason:</strong> {{ $proposal->rejection_reason }}</p>
    </div>
@endif

<div class="card nu-card border-0 shadow-sm p-3 p-md-5">
    <!-- Document Header -->
    <div class="text-center mb-5 pb-3 border-bottom border-2" style="border-color: var(--nu-gold) !important;">
        <h1 class="h3 fw-bold text-uppercase mb-1" style="color: var(--nu-blue);">Event Proposal</h1>
        <p class="text-muted mb-0">No. {{ $proposal->proposal_number }}</p>
    </div>

    <!-- Event Info -->
    <div class="row mb-5">
        <div class="col-md-8">
            <h4 class="fw-bold mb-3">{{ $proposal->event->title ?? 'N/A' }}</h4>
            <div class="d-flex mb-2">
                <div style="width: 120px;" class="fw-bold text-muted">Date & Time:</div>
                <div>{{ $proposal->event ? $proposal->event->event_date->format('F d, Y') . ' · ' . \Carbon\Carbon::parse($proposal->event->start_time)->format('h:i A') . '–' . \Carbon\Carbon::parse($proposal->event->end_time)->format('h:i A') : 'N/A' }}</div>
            </div>
            <div class="d-flex mb-2">
                <div style="width: 120px;" class="fw-bold text-muted">Venue:</div>
                <div>{{ $proposal->event->venue ?? 'N/A' }}</div>
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <div class="mb-2">
                <span class="fw-bold text-muted">Submitted Date:</span><br>
                {{ $proposal->created_at->format('M d, Y') }}
            </div>
        </div>
    </div>

    <!-- Content Sections -->
    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">1. Event Overview</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->event_overview }}</p>
    </div>

    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">2. Objectives</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->objectives }}</p>
    </div>

    @if($proposal->target_audience)
    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">3. Target Audience</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->target_audience }}</p>
    </div>
    @endif

    <div class="row mb-4">
        @if($proposal->venue_details)
        <div class="col-md-6 mb-3">
            <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Venue Details</h5>
            <p class="whitespace-pre-wrap">{{ $proposal->venue_details }}</p>
        </div>
        @endif
        
        @if($proposal->schedule_details)
        <div class="col-md-6 mb-3">
            <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Schedule Details</h5>
            <p class="whitespace-pre-wrap">{{ $proposal->schedule_details }}</p>
        </div>
        @endif
    </div>

    @if($proposal->requirements)
    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Requirements / Logistics</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->requirements }}</p>
    </div>
    @endif

    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Equipment Request Checklist</h5>
        @forelse($proposal->event->equipmentRequests as $item)
            <div class="d-flex justify-content-between gap-3 border-bottom py-2"><span><i class="bi bi-square me-2"></i>{{ $item->item_name }}@if($item->purpose)<small class="text-muted d-block ms-4">{{ $item->purpose }}</small>@endif</span><strong>Qty {{ $item->quantity }}</strong></div>
        @empty
            <p class="text-muted">No equipment requested.</p>
        @endforelse
    </div>

    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Previous-Event Recommendations Applied</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->recommendations }}</p>
        <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Reviewed by requester {{ $proposal->recommendations_applied_at?->format('M d, Y h:i A') }}</small>
    </div>

    @if($proposal->expected_outcomes)
    <div class="mb-4">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Expected Outcomes</h5>
        <p class="whitespace-pre-wrap">{{ $proposal->expected_outcomes }}</p>
    </div>
    @endif

    <div class="mb-5">
        <h5 class="fw-bold pb-2 border-bottom" style="color: var(--nu-blue-dk);">Estimated Budget</h5>
        <h4 class="fw-bold" style="color: var(--nu-gold);">₱{{ number_format($proposal->estimated_budget, 2) }}</h4>
    </div>

    <!-- Signatures -->
    <div class="row mt-5 pt-4">
        <div class="col-md-6 mb-4">
            <div class="fw-bold text-muted mb-4">Prepared By:</div>
            <div class="border-bottom border-dark d-inline-block px-3 pb-1 mb-2 min-w-200 text-center">
                <strong>{{ $proposal->preparedBy->full_name ?? 'N/A' }}</strong>
            </div>
            <div class="text-muted small">Date: {{ $proposal->created_at->format('M d, Y') }}</div>
        </div>
        
        <div class="col-md-6 mb-4">
            <div class="fw-bold text-muted mb-4">Approved By:</div>
            <div class="border-bottom border-dark d-inline-block px-3 pb-1 mb-2 min-w-200 text-center" style="min-width: 200px;">
                @if($proposal->status === 'approved')
                    <strong class="text-success">{{ $proposal->approvedBy->full_name ?? 'Administrator' }}</strong>
                    <i class="bi bi-check-circle-fill text-success ms-1"></i>
                @else
                    <span class="text-muted font-monospace">______________________</span>
                @endif
            </div>
            <div class="text-muted small">
                Date: {{ $proposal->approved_at ? \Carbon\Carbon::parse($proposal->approved_at)->format('M d, Y') : '____________' }}
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
@if(Auth::user()->role === 'admin' && $proposal->status === 'submitted')
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('proposal.reject', $proposal->id) }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectModalLabel">Reject Proposal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="rejection_reason" class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" required placeholder="Please provide a reason so the organizer can adjust and resubmit."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>
@endif

<style>
.whitespace-pre-wrap { white-space: pre-wrap; }
.min-w-200 { min-width: 200px; }
</style>
@endsection
