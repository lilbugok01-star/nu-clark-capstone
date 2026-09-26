@extends('layouts.app')
@section('title', 'Approvals Dashboard')
@section('content')
<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 style="color:var(--nu-blue);font-weight:800;margin:0">
            <i class="bi bi-ui-checks"></i> Approvals Dashboard
        </h4>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-nu-blue btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#uploadSignatureModal">
                <i class="bi bi-pencil-square me-1"></i> Update Signature
            </button>
            <span class="badge bg-primary px-3 py-2 rounded-pill">{{ ucfirst(str_replace('_', ' ', $user->role)) }}</span>
        </div>
    </div>

    @if(!$user->e_signature_path)
        <div class="alert alert-danger px-4 py-3 border-0 shadow-sm rounded-3 d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-exclamation-octagon fs-4"></i>
            <div class="flex-grow-1">
                <strong>Action Required:</strong> You must 
                <button class="btn btn-link p-0 alert-link border-0 align-baseline" data-bs-toggle="modal" data-bs-target="#uploadSignatureModal">upload your E-Signature</button> 
                before you can approve any requests.
            </div>
            <button class="btn btn-danger btn-sm px-3 fw-bold rounded-pill" data-bs-toggle="modal" data-bs-target="#uploadSignatureModal">
                <i class="bi bi-upload me-1"></i> Fix Now
            </button>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Nav Tabs -->
            <ul class="nav nav-tabs border-bottom-0 mb-3" id="approvalTabs" role="tablist">
                @if(in_array($user->role, ['adviser', 'department_head', 'dean', 'executive_director']))
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold px-4 border-0 rounded-pill me-2" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">
                        <i class="bi bi-calendar-event me-1"></i> Events ({{ $pendingEvents->count() }})
                    </button>
                </li>
                @endif
                @if(in_array($user->role, ['student_development', 'program_chair', 'dean', 'executive_director']))
                <li class="nav-item" role="presentation">
                    @php $venuesOnlyRole = !in_array($user->role, ['adviser', 'department_head', 'dean', 'executive_director']); @endphp
                    <button class="nav-link {{ $venuesOnlyRole ? 'active' : '' }} fw-bold px-4 border-0 rounded-pill" id="venues-tab" data-bs-toggle="tab" data-bs-target="#venues" type="button" role="tab">
                        <i class="bi bi-building-check me-1"></i> Venues ({{ $pendingVenues->count() }})
                    </button>
                </li>
                @endif
            </ul>

            <div class="tab-content" id="approvalTabsContent">
                <!-- Events Tab -->
                @if(in_array($user->role, ['adviser', 'department_head', 'dean', 'executive_director']))
                <div class="tab-pane fade show active" id="events" role="tabpanel">
                    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
                        <div class="card-body p-4">
                            @forelse($pendingEvents as $event)
                                @php
                                    $evidenceOpened = $event->approvedProposal && $event->approvals->contains(fn($approval) =>
                                        $approval->approver_id === $user->id &&
                                        $approval->role_level === $user->role &&
                                        $approval->reviewed_proposal_id === $event->approvedProposal->id &&
                                        $approval->proposal_reviewed_at !== null
                                    );
                                @endphp
                                <div class="border rounded-3 p-3 mb-3 hover-shadow-sm transition-all bg-light">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="fw-bold mb-1 text-primary">{{ $event->title }}</h6>
                                            <div class="text-muted small mb-2">
                                                <i class="bi bi-calendar-event"></i> {{ $event->event_date->format('M d, Y') }} 
                                                &nbsp;&nbsp;<i class="bi bi-clock"></i> {{ \Carbon\Carbon::parse($event->start_time)->format('h:i A') }} - {{ \Carbon\Carbon::parse($event->end_time)->format('h:i A') }}
                                                &nbsp;&nbsp;<i class="bi bi-geo-alt"></i> {{ $event->venue }}
                                            </div>
                                            <div class="small text-secondary mb-2">
                                                <strong>Organizer:</strong> {{ $event->organizer->full_name }}
                                                <br>
                                                {{ Str::limit($event->description, 100) }}
                                            </div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="{{ route('event.show', $event->id) }}" class="btn btn-sm btn-outline-primary rounded-pill px-3">View Details</a>
                                                @if($event->approvedProposal)
                                                    <a href="{{ route('proposal.export-pdf', $event->approvedProposal) }}" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-paperclip me-1"></i>Approved Proposal Evidence</a>
                                                @else
                                                    <span class="badge bg-danger align-self-center">Approved proposal missing</span>
                                                @endif
                                            </div>
                                            @if($event->equipmentRequests->isNotEmpty())
                                                <div class="mt-2 p-2 bg-white border rounded small"><strong>Equipment checklist:</strong> {{ $event->equipmentRequests->map(fn($item) => $item->item_name . ' × ' . $item->quantity)->implode(', ') }}</div>
                                            @endif
                                        </div>
                                        <div class="text-end" style="min-width: 140px;">
                                             <form action="{{ route('approver.events.approve', $event->id) }}" method="POST" class="mb-1" onsubmit="return confirm('Approve this event using your E-Signature?')">
                                                 @csrf
                                                 <div class="form-check text-start mb-2 small"><input class="form-check-input" type="checkbox" name="proposal_reviewed" value="1" id="proposalReviewed{{ $event->id }}" required {{ !$evidenceOpened ? 'disabled' : '' }}><label class="form-check-label" for="proposalReviewed{{ $event->id }}">Proposal checked</label></div>
                                                 <button class="btn btn-sm btn-success w-100 fw-bold mb-1" {{ (!$user->e_signature_path || !$evidenceOpened) ? 'disabled' : '' }}>
                                                    <i class="bi bi-check2-circle"></i> Approve
                                                </button>
                                            </form>
                                            @if($event->approvedProposal && !$evidenceOpened)
                                                <div class="text-muted text-center mb-2" style="font-size:.68rem;line-height:1.2">Open the evidence, then refresh this page to approve.</div>
                                            @elseif($evidenceOpened)
                                                <div class="text-success text-center mb-2" style="font-size:.68rem"><i class="bi bi-check-circle-fill"></i> Evidence opened</div>
                                            @endif
                                            <button class="btn btn-sm btn-outline-danger w-100 fw-bold" data-bs-toggle="modal" data-bs-target="#rejectEventModal{{ $event->id }}" {{ !$user->e_signature_path ? 'disabled' : '' }}>
                                                <i class="bi bi-x-circle"></i> Reject
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Rejection Modal for Event -->
                                <div class="modal fade" id="rejectEventModal{{ $event->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <form action="{{ route('approver.events.reject', $event->id) }}" method="POST">
                                                @csrf
                                                <div class="modal-header border-0 pb-0">
                                                    <h5 class="modal-title fw-bold">Reject Event</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="small text-muted mb-3">Please provide a reason for rejecting <strong>{{ $event->title }}</strong>. This feedback will be sent to the organizer.</p>
                                                    <textarea name="comments" class="form-control" rows="4" placeholder="Reason for rejection..." required></textarea>
                                                </div>
                                                <div class="modal-footer border-0 pt-0">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger px-4 fw-bold">Confirm Rejection</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-check-circle fs-1 text-success opacity-50 mb-2"></i>
                                    <p class="mb-0">No pending events for your approval.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                @endif

                <!-- Venues Tab -->
                @if(in_array($user->role, ['student_development', 'program_chair', 'dean', 'executive_director']))
                <div class="tab-pane fade {{ $venuesOnlyRole ? 'active show' : '' }}" id="venues" role="tabpanel">
                    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
                        <div class="card-body p-4">
                            @forelse($pendingVenues as $venue)
                                <div class="border rounded-3 p-3 mb-3 hover-shadow-sm transition-all bg-light">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="fw-bold mb-1 text-primary">
                                                {{ $venue->event ? $venue->event->title : ($venue->event_title ?: 'Untitled Event') }}
                                            </h6>
                                            <div class="text-muted small mb-2">
                                                <i class="bi bi-building"></i> <strong>{{ $venue->venue_name }}</strong>
                                                &nbsp;&nbsp;<i class="bi bi-calendar-event"></i> {{ $venue->reserved_date->format('M d, Y') }} 
                                                <br>
                                                <i class="bi bi-clock"></i> {{ \Carbon\Carbon::parse($venue->start_time)->format('h:i A') }} - {{ \Carbon\Carbon::parse($venue->end_time)->format('h:i A') }}
                                                &nbsp;&nbsp;<i class="bi bi-people"></i> {{ $venue->expected_attendees }} attendees
                                            </div>
                                            <div class="small text-secondary mb-2">
                                                <strong>Department:</strong> {{ $venue->reservedBy->full_name }}
                                                @if($venue->purpose)
                                                    <br><strong>Purpose:</strong> {{ $venue->purpose }}
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-end" style="min-width: 140px;">
                                            @php
                                                $myApproval = $venue->approvals->where('approver_id', $user->id)->where('status', 'pending')->first();
                                                $isOpen = $myApproval && $myApproval->opened_at;
                                            @endphp

                                            @if(!$isOpen)
                                                <a href="{{ route('approver.venues.form', $venue->id) }}" target="_blank" class="btn btn-sm btn-outline-secondary w-100 fw-bold mb-1" title="View Permission Document">
                                                    <i class="bi bi-file-earmark-text me-1"></i> View Form
                                                </a>
                                                <form action="{{ route('approver.venues.open', $venue->id) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-warning w-100 fw-bold shadow-sm mb-1" style="color:var(--nu-blue)">
                                                        <i class="bi bi-envelope-open me-1"></i> Open Document
                                                    </button>
                                                </form>
                                                <div class="text-muted text-center" style="font-size:0.65rem; line-height:1.2;">Open to enable<br>Approve / Reject action</div>
                                            @else
                                                <div class="mb-2 text-warning fw-bold text-center" style="font-size:0.7rem; background:rgba(255,193,7,.1); padding:4px; border-radius:4px;">
                                                    <i class="bi bi-clock-history"></i> Opened {{ $myApproval->opened_at->diffForHumans() }}
                                                </div>
                                                <a href="{{ route('approver.venues.form', $venue->id) }}" target="_blank" class="btn btn-sm btn-outline-primary w-100 fw-bold mb-2" title="View Permission Document">
                                                    <i class="bi bi-file-earmark-text me-1"></i> View Form
                                                </a>
                                                <form action="{{ route('approver.venues.approve', $venue->id) }}" method="POST" class="mb-1" onsubmit="return confirm('Approve this venue reservation using your E-Signature?')">
                                                    @csrf
                                                    <button class="btn btn-sm btn-success w-100 fw-bold mb-1" {{ !$user->e_signature_path ? 'disabled' : '' }}>
                                                        <i class="bi bi-check2-circle"></i> Approve
                                                    </button>
                                                </form>
                                                <button class="btn btn-sm btn-outline-danger w-100 fw-bold" data-bs-toggle="modal" data-bs-target="#rejectVenueModal{{ $venue->id }}" {{ !$user->e_signature_path ? 'disabled' : '' }}>
                                                    <i class="bi bi-x-circle"></i> Reject
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <!-- Rejection Modal for Venue -->
                                <div class="modal fade" id="rejectVenueModal{{ $venue->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <form action="{{ route('approver.venues.reject', $venue->id) }}" method="POST">
                                                @csrf
                                                <div class="modal-header border-0 pb-0">
                                                    <h5 class="modal-title fw-bold">Reject Venue Reservation</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="small text-muted mb-3">Please provide a reason for rejecting this reservation. This feedback will be sent to the department.</p>
                                                    <textarea name="comments" class="form-control" rows="4" placeholder="Reason for rejection..." required></textarea>
                                                </div>
                                                <div class="modal-footer border-0 pt-0">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger px-4 fw-bold">Confirm Rejection</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-building fs-1 text-success opacity-50 mb-2"></i>
                                    <p class="mb-0">No pending venue reservations for your approval.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- History sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                    <h6 class="fw-bold mb-0 text-dark border-bottom pb-2" style="border-color:var(--gray-300)!important;border-width:2px!important;display:inline-block">Approval History</h6>
                </div>
                <div class="card-body p-4 pt-3">
                    <div class="small fw-bold text-muted mb-2 text-uppercase" style="letter-spacing: 0.5px; font-size: 10px;">Recent Events</div>
                    <ul class="list-unstyled mb-4">
                        @forelse($historyEvents as $hist)
                            <li class="mb-3 pb-2 border-bottom {{ $loop->last ? 'mb-0 pb-0 border-0' : '' }}">
                                <div class="fw-semibold text-truncate" style="font-size:0.85rem;">{{ $hist->event->title ?? 'Unknown Event' }}</div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="badge {{ $hist->status === 'approved' ? 'bg-success' : 'bg-danger' }} bg-opacity-10 text-{{ $hist->status === 'approved' ? 'success' : 'danger' }} px-2 py-1" style="font-size:0.65rem">
                                        {{ strtoupper($hist->status) }}
                                    </span>
                                    <span class="small text-muted" style="font-size:0.7rem">{{ $hist->created_at->format('M d, g:i A') }}</span>
                                </div>
                            </li>
                        @empty
                            <p class="small text-muted text-center py-2">No event history.</p>
                        @endforelse
                    </ul>

                    <div class="small fw-bold text-muted mb-2 text-uppercase" style="letter-spacing: 0.5px; font-size: 10px;">Recent Venues</div>
                    <ul class="list-unstyled mb-0">
                        @forelse($historyVenues as $hV)
                            <li class="mb-3 pb-2 border-bottom {{ $loop->last ? 'mb-0 pb-0 border-0' : '' }}">
                                <div class="fw-semibold text-truncate" style="font-size:0.85rem;">
                                    {{ $hV->venueReservation->event->title ?? ($hV->venueReservation->event_title ?? 'Untitled') }}
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="badge {{ $hV->status === 'approved' ? 'bg-success' : 'bg-danger' }} bg-opacity-10 text-{{ $hV->status === 'approved' ? 'success' : 'danger' }} px-2 py-1" style="font-size:0.65rem">
                                        {{ strtoupper($hV->status) }}
                                    </span>
                                    <span class="small text-muted" style="font-size:0.7rem">{{ $hV->created_at->format('M d, g:i A') }}</span>
                                </div>
                            </li>
                        @empty
                            <p class="small text-muted text-center py-2">No venue history.</p>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Events Calendar -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="nu-card p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-calendar3 me-2" style="color:var(--nu-gold)"></i>Events Calendar</h6>
                <x-event-calendar calendarId="approverCalendar" rightToolbar="dayGridMonth,timeGridWeek" />
            </div>
        </div>
    </div>
</div>

<style>
    .nav-tabs .nav-link {
        color: var(--gray-600);
        background: var(--gray-100);
        margin-bottom: 0;
        transition: all 0.2s ease;
    }
    .nav-tabs .nav-link.active {
        background-color: var(--nu-blue) !important;
        color: white !important;
        box-shadow: 0 4px 12px rgba(0,48,135,0.2);
    }
    .hover-shadow-sm:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        border-color: var(--nu-blue) !important;
    }
</style>

@endsection

<!-- Upload Signature Modal -->
<div class="modal fade" id="uploadSignatureModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-800" style="color:var(--nu-blue)">
                    <i class="bi bi-pencil-square me-2"></i> Update E-Signature
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('approver.profile.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="modal-body p-4 text-center">
                    <p class="text-muted small mb-4">Upload a clear photo or scan of your signature (PNG/JPG). This will be used to sign approved documents.</p>
                    
                    @if($user->e_signature_path)
                    <div class="mb-4">
                        <div class="small text-muted mb-2">Current Signature:</div>
                        <div class="p-3 border rounded-3 bg-white mx-auto" style="max-width:320px">
                            <img src="{{ \App\Helpers\StorageUrl::url($user->e_signature_path) }}" alt="Signature" class="img-fluid" style="max-height:160px;max-width:100%;object-fit:contain">
                        </div>
                    </div>
                    @else
                    <div class="mb-4 py-4 border-2 border-dashed rounded-4 text-muted bg-light">
                        <i class="bi bi-image fs-1 opacity-25"></i>
                        <p class="mb-0 mt-2">No signature uploaded yet</p>
                    </div>
                    @endif

                    <div class="text-start">
                        <label class="form-label fw-bold small">Choose New Image</label>
                        <input type="file" name="e_signature" class="form-control" accept="image/*" required>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Signature</button>
                </div>
            </form>
        </div>
    </div>
</div>
