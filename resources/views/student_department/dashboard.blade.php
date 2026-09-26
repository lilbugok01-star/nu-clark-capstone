@extends('layouts.app')
@section('title', 'Project Venue Reservations')
@section('content')
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h4 class="fw-800 mb-0" style="color:var(--nu-blue)"><i class="bi bi-building me-2" style="color:var(--nu-gold)"></i>Venue Reservations</h4>
            <p class="text-muted small mb-0">Track your facility requests like a parcel</p>
        </div>
    </div>



    <!-- Facility Overview -->
    <div class="row g-3 mb-4">
        @foreach([
            ['name' => 'NU Clark Gymnasium', 'icon' => 'trophy'],
            ['name' => 'NU Clark Auditorium', 'icon' => 'mic'],
            ['name' => 'NU Clark Library',    'icon' => 'book'],
            ['name' => 'Mini Chapel',         'icon' => 'moon-stars'],
            ['name' => '4th Floor Rooms',     'icon' => 'door-open'],
            ['name' => '5th Floor Rooms',     'icon' => 'door-open'],
            ['name' => '6th Floor Rooms',     'icon' => 'door-open'],
            ['name' => '7th & 8th Floor',     'icon' => 'door-open']
        ] as $vn)
        <div class="col-md-3 col-6">
            <div class="venue-card text-center">
                <i class="bi bi-{{ $vn['icon'] }} mb-2" style="font-size:1.6rem;color:var(--nu-blue)"></i>
                <div class="fw-600 small">{{ $vn['name'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    <!-- Venue Availability Calendar -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="nu-card p-4">
                <h6 class="fw-700 mb-3"><i class="bi bi-calendar3 me-2" style="color:var(--nu-gold)"></i>Venue Availability Calendar</h6>
                <x-event-calendar calendarId="calendar" eventsUrl="{{ route('student_department.venue.events.json') }}" initialView="timeGridWeek" />
            </div>
        </div>
    </div>
    
    @push('scripts')
    <script>
        function toggleCustomFields() {
            const evSelect = document.getElementById('event_select');
            const evInput = document.getElementById('custom_event_title');
            if (evSelect.value === 'custom') {
                evInput.style.display = 'block';
                evInput.disabled = false;
                evInput.required = true;
            } else {
                evInput.style.display = 'none';
                evInput.disabled = true;
                evInput.required = false;
                populateEquipmentFromEvent(evSelect.options[evSelect.selectedIndex]);
            }
        }

        function populateEquipmentFromEvent(option) {
            const rows = document.querySelectorAll('.department-equipment-row');
            if (!option || !option.dataset.equipment || !rows.length) return;
            let items = [];
            try { items = JSON.parse(option.dataset.equipment); } catch (error) { return; }
            rows.forEach((row, index) => {
                row.querySelector('[data-field="item_name"]').value = items[index]?.item_name || '';
                row.querySelector('[data-field="quantity"]').value = items[index]?.quantity || 1;
                row.querySelector('[data-field="purpose"]').value = items[index]?.purpose || '';
            });
        }

        function toggleCustomVenueField() {
            const customChk = document.getElementById('chk_modal_custom');
            const customInput = document.getElementById('custom_venue_name_input');
            const label = document.querySelector(`label[for="${customChk.id}"]`);
            const icon = label.querySelector('.check-icon');

            if (customChk.checked) {
                customInput.style.display = 'block';
                customInput.disabled = false;
                customInput.required = true;
                label.classList.add('bg-warning', 'text-dark');
                if (icon) icon.classList.remove('d-none');
            } else {
                customInput.style.display = 'none';
                customInput.disabled = true;
                customInput.required = false;
                customInput.value = '';
                label.classList.remove('bg-warning', 'text-dark');
                if (icon) icon.classList.add('d-none');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Monitor modal check-boxes to show/hide checkmark icons
            document.querySelectorAll('.room-checkbox').forEach(cb => {
                cb.addEventListener('change', function() {
                    const label = document.querySelector(`label[for="${this.id}"]`);
                    if (!label) return;
                    const icon = label.querySelector('.check-icon');
                    if (this.checked) {
                        label.classList.add('bg-primary', 'text-white');
                        if (icon) icon.classList.remove('d-none');
                    } else {
                        label.classList.remove('bg-primary', 'text-white');
                        if (icon) icon.classList.add('d-none');
                    }
                });
            });

            // Set up event listeners for modal changes to dynamically check availability
            const modalDate = document.querySelector('input[name="reserved_date"]');
            const modalStart = document.querySelector('input[name="start_time"]');
            const modalEnd = document.querySelector('input[name="end_time"]');

            if (modalDate && modalStart && modalEnd) {
                [modalDate, modalStart, modalEnd].forEach(input => {
                    input.addEventListener('change', updateModalAvailability);
                });
            }


        });

        function updateModalAvailability() {
            const dateVal = document.querySelector('input[name="reserved_date"]').value;
            const startVal = document.querySelector('input[name="start_time"]').value;
            const endVal = document.querySelector('input[name="end_time"]').value;

            if (!dateVal) return;

            let url = `{{ route('student_department.venue.availability') }}?date=${dateVal}`;
            if (startVal && endVal) {
                url += `&start_time=${startVal}&end_time=${endVal}`;
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    document.querySelectorAll('.room-checkbox').forEach(checkbox => {
                        const roomVal = checkbox.value;
                        const label = document.querySelector(`label[for="${checkbox.id}"]`);
                        if (!label) return;
                        
                        const icon = label.querySelector('.check-icon');

                        if (data[roomVal] && data[roomVal].status === 'occupied') {
                            const res = data[roomVal].reservation;
                            checkbox.disabled = true;
                            checkbox.checked = false;
                            label.classList.add('disabled-occupied');
                            label.classList.remove('btn-outline-primary', 'bg-primary', 'text-white');
                            if (icon) icon.classList.add('d-none');
                            label.setAttribute('title', `${roomVal} is reserved by ${res.reserved_by} for ${res.event_title} (${res.start_time} - ${res.end_time})`);
                            
                            let badge = label.querySelector('.occupied-badge');
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'badge bg-danger ms-auto occupied-badge';
                                badge.style.fontSize = '0.6rem';
                                badge.innerHTML = `<i class="bi bi-x-circle-fill"></i> Occupied`;
                                label.appendChild(badge);
                            }
                        } else {
                            checkbox.disabled = false;
                            label.classList.remove('disabled-occupied');
                            label.classList.add('btn-outline-primary');
                            label.removeAttribute('title');
                            
                            const badge = label.querySelector('.occupied-badge');
                            if (badge) {
                                badge.remove();
                            }
                            // Restore checked class if checked
                            if (checkbox.checked) {
                                label.classList.add('bg-primary', 'text-white');
                                if (icon) icon.classList.remove('d-none');
                            }
                        }
                    });
                })
                .catch(error => console.error('Error fetching room availability:', error));
        }
    </script>
    @endpush

    <!-- My Reservations -->
    <div class="nu-card">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-700 mb-0"><i class="bi bi-list-check me-2" style="color:var(--nu-gold)"></i>My Venue Requests</h6>
            <button class="btn btn-sm btn-nu-blue" data-bs-toggle="modal" data-bs-target="#newReservationModal">
                <i class="bi bi-plus me-1"></i>New Request
            </button>
        </div>
        
        <div class="border-bottom">
            <ul class="nav nav-tabs nav-justified border-0" id="reservationTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold py-3 border-0 rounded-0" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-requests" type="button" role="tab" style="color:var(--nu-blue)">
                        Active Requests @if($activeReservations->count() > 0)<span class="badge bg-danger ms-1">{{ $activeReservations->count() }}</span>@endif
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold py-3 border-0 rounded-0 text-muted" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-requests" type="button" role="tab">
                        Request History
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="reservationTabsContent">
            <!-- ACTIVE REQUESTS TAB -->
            <div class="tab-pane fade show active p-4" id="active-requests" role="tabpanel">
                @forelse($activeReservations as $res)
                <div class="reservation-track-card border rounded-3 p-4 mb-4 bg-white shadow-sm position-relative">
                    <div class="d-flex justify-content-between align-items-start mb-4 border-bottom pb-3">
                        <div>
                            <h5 class="fw-800 mb-1" style="color:var(--nu-blue)">{{ $res->event?->title ?? $res->event_title ?? 'Untitled Event' }}</h5>
                            <div class="d-flex gap-3 flex-wrap small text-muted">
                                <span><i class="bi bi-building me-1"></i>{{ $res->venue_name }}</span>
                                <span><i class="bi bi-calendar-event me-1"></i>{{ $res->reserved_date->format('M d, Y') }}</span>
                                <span><i class="bi bi-clock me-1"></i>{{ substr($res->start_time,0,5) }} – {{ substr($res->end_time,0,5) }}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i>Processing</span>
                            <div class="mt-2 d-flex justify-content-end gap-2">
                                <a href="{{ route('student_department.venue.form', $res->id) }}" class="btn btn-sm btn-outline-secondary fw-bold px-3 py-1 rounded-pill" style="font-size: 0.7rem;">
                                    <i class="bi bi-eye me-1"></i> View Form
                                </a>
                                @if(str_starts_with($res->status, 'pending_'))
                                <form action="{{ route('student_department.venue.delete', $res->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to cancel and delete this request?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger fw-bold px-3 py-1 rounded-pill" style="font-size: 0.7rem;" title="Cancel Request">
                                        <i class="bi bi-trash me-1"></i> Cancel
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Shopee-style Tracker Integration (Dynamic File Hunting) -->
                    @php
                        $currentStep = 0;
                        $isRejected = false;
                        $isFullyApproved = false;
                        
                        // Find the index of the current 'pending_' role
                        $matchRole = str_replace('pending_', '', $res->status);
                        
                        // Some statuses have slight naming differences from roles
                        if ($matchRole === 'student_dev') $matchRole = 'student_development';
                        if ($matchRole === 'director')    $matchRole = 'executive_director';
                        if ($matchRole === 'dept_head')   $matchRole = 'department_head';
                        
                        $sigStep = $signatories->firstWhere('role', $matchRole);
                        if ($sigStep) {
                            $currentStep = $sigStep->step_order;
                        }
                        
                        $totalSteps = $signatories->count() + 1; // +1 for Final Approved
                    @endphp

                    <div class="tracker-wrapper mt-2 mb-2 overflow-auto px-2 py-1">
                        <div class="tracker-steps d-flex justify-content-between position-relative" style="min-width: 600px;">
                            <!-- Progress Line Fixed: top is 20px so it centers directly behind 40px icons -->
                            <div class="progress-line position-absolute start-0 w-100" style="top: 20px; height: 4px; background: #e9ecef; z-index: 1;"></div>
                            <div class="progress-line-fill position-absolute start-0 transition-all" 
                                 style="top: 20px; height: 4px; background: var(--nu-blue); z-index: 2; width: {{ (($currentStep > 0 ? $currentStep - 1 : 0) * (100 / max(1, $totalSteps - 1))) }}%;"></div>

                            <!-- Dynamic Steps -->
                            @foreach($signatories as $index => $sig)
                                @php
                                    $stepNum = $sig->step_order;
                                    $isPast = $currentStep > $stepNum;
                                    $isCurrent = $currentStep === $stepNum;
                                    
                                    // Check if opened
                                    $approvalDoc = $res->approvals->where('role_level', $sig->role)->first();
                                    $hasOpened = $approvalDoc && $approvalDoc->opened_at;
                                    
                                    $iconClass = 'bi-person-circle';
                                    if ($sig->role === 'student_development') $iconClass = 'bi-person-check';
                                    if ($sig->role === 'program_chair') $iconClass = 'bi-mortarboard';
                                    if ($sig->role === 'dean') $iconClass = 'bi-journal-check';
                                    if ($sig->role === 'executive_director') $iconClass = 'bi-shield-check';
                                    if ($sig->role === 'adviser') $iconClass = 'bi-people';
                                    if ($sig->role === 'department_head') $iconClass = 'bi-briefcase';

                                    $bgClass = 'bg-white text-muted border-light';
                                    $textClass = 'text-muted';
                                    
                                    if ($isPast) {
                                        $bgClass = 'bg-primary text-white border-primary';
                                        $textClass = 'text-primary';
                                    } elseif ($isCurrent) {
                                        if ($hasOpened) {
                                            $bgClass = 'bg-warning text-dark border-warning shadow-sm';
                                            $textClass = 'text-warning';
                                        } else {
                                            $bgClass = 'bg-white text-primary';
                                            $textClass = 'text-primary';
                                        }
                                    }
                                @endphp
                                <div class="step text-center position-relative" style="z-index: 3; width: 100px;">
                                    <div class="step-icon mx-auto rounded-circle d-flex align-items-center justify-content-center border-4 {{ $bgClass }}" 
                                         style="width: 40px; height: 40px; border-color: {{ $isCurrent && !$hasOpened ? 'var(--nu-blue) !important' : '' }}">
                                        <i class="bi {{ $iconClass }} fs-5"></i>
                                    </div>
                                    <div class="step-label small fw-bold mt-2 {{ $textClass }}">
                                        {{ $sig->position_label }}
                                        @if($isCurrent && $hasOpened)
                                            <div style="font-size:0.65rem; line-height:1.1;" class="text-warning mt-1"><i class="bi bi-eye"></i> Opened</div>
                                        @elseif($isCurrent && !$hasOpened)
                                            <div style="font-size:0.65rem; line-height:1.1;" class="text-primary mt-1">Pending</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <!-- Step Final: Approved -->
                            <div class="step text-center position-relative" style="z-index: 3; width: 80px;">
                                <div class="step-icon mx-auto rounded-circle d-flex align-items-center justify-content-center border-4 bg-white text-muted border-light" style="width: 40px; height: 40px;">
                                    <i class="bi bi-flag fs-5"></i>
                                </div>
                                <div class="step-label small fw-bold mt-2 text-muted">Finished</div>
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-building" style="font-size:2rem;opacity:.3"></i>
                    <p class="small mt-2 mb-0">No active reservations.</p>
                </div>
                @endforelse
            </div>

            <!-- HISTORY TAB -->
            <div class="tab-pane fade p-4" id="history-requests" role="tabpanel">
                @forelse($historyReservations as $res)
                <div class="reservation-track-card border rounded-3 p-4 mb-4 bg-light shadow-sm">
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <h5 class="fw-800 mb-1" style="{{ $res->status === 'approved' ? 'color:var(--nu-blue)' : 'color:#6c757d' }}">
                                {{ $res->event?->title ?? $res->event_title ?? 'Untitled Event' }}
                            </h5>
                            <div class="d-flex gap-3 flex-wrap small text-muted">
                                <span><i class="bi bi-building me-1"></i>{{ $res->venue_name }}</span>
                                <span><i class="bi bi-calendar-event me-1"></i>{{ $res->reserved_date->format('M d, Y') }}</span>
                                <span><i class="bi bi-clock me-1"></i>{{ substr($res->start_time,0,5) }} – {{ substr($res->end_time,0,5) }}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            @if($res->status === 'approved')
                                <span class="badge bg-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle me-1"></i>Fully Approved</span>
                                <div class="mt-2">
                                    <a href="{{ route('student_department.venue.form', $res->id) }}" class="btn btn-sm btn-outline-primary fw-bold px-3 py-1 rounded-pill">
                                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> View Permission Form
                                    </a>
                                </div>
                            @elseif($res->status === 'rejected')
                                <span class="badge bg-danger px-3 py-2 rounded-pill"><i class="bi bi-x-circle me-1"></i>Declined</span>
                            @elseif($res->status === 'cancelled')
                                <span class="badge bg-secondary px-3 py-2 rounded-pill"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>
                            @endif
                        </div>
                    </div>

                    {{-- Shopee-Style Approval Timeline & Signatory Notes --}}
                    @if($res->approvals->isNotEmpty())
                        <div class="mt-3 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                            <div class="fw-700 mb-2 small text-uppercase" style="color:var(--nu-blue);font-size:.72rem;letter-spacing:.03em">
                                <i class="bi bi-clock-history me-1" style="color:var(--nu-gold)"></i> Approval Timeline & Revision Notes
                            </div>
                            <div class="d-flex flex-column gap-2">
                                @foreach($res->approvals as $appr)
                                    <div class="d-flex align-items-start gap-2 p-2 rounded-2" style="background:#ffffff;border:1px solid #e2e8f0">
                                        <div class="flex-shrink-0 mt-1">
                                            @if($appr->status === 'approved')
                                                <span class="badge bg-success rounded-circle p-1"><i class="bi bi-check text-white"></i></span>
                                            @elseif($appr->status === 'rejected')
                                                <span class="badge bg-danger rounded-circle p-1"><i class="bi bi-x text-white"></i></span>
                                            @else
                                                <span class="badge bg-warning rounded-circle p-1"><i class="bi bi-clock text-dark"></i></span>
                                            @endif
                                        </div>
                                        <div class="flex-grow-1" style="font-size:.8rem">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong style="color:var(--nu-blue)">{{ $appr->approver->full_name ?? 'Signatory' }}</strong>
                                                    <span class="badge bg-light text-dark ms-1" style="font-size:.65rem">{{ ucfirst(str_replace('_', ' ', $appr->role_level)) }}</span>
                                                </div>
                                                <span class="text-muted" style="font-size:.7rem">{{ $appr->updated_at?->format('M d, Y h:i A') }}</span>
                                            </div>
                                            @if($appr->comments)
                                                <div class="mt-1.5 p-2 rounded text-dark" style="background:{{ $appr->status === 'rejected' ? '#fef2f2' : '#f1f5f9' }};border-left:3px solid {{ $appr->status === 'rejected' ? '#ef4444' : '#0284c7' }};font-size:.78rem">
                                                    <i class="bi bi-chat-left-quote-fill me-1" style="color:{{ $appr->status === 'rejected' ? '#dc2626' : '#0284c7' }}"></i> 
                                                    <strong>Feedback:</strong> {{ $appr->comments }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i> Awaiting first signatory review.</div>
                    @endif
                </div>
                @empty
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-archive" style="font-size:2rem;opacity:.3"></i>
                    <p class="small mt-2 mb-0">No past reservations found.</p>
                </div>
                @endforelse
            </div>
        </div>
        </div>
    </div>
</div>

<!-- New Reservation Modal -->
<div class="modal fade" id="newReservationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-3">
            <div class="modal-header" style="background:var(--nu-blue);border:none">
                <h5 class="modal-title text-white fw-700"><i class="bi bi-building me-2" style="color:var(--nu-gold)"></i>Request Venue Reservation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('student_department.venue.store') }}" method="POST">
                @csrf
                <div class="modal-body p-4">
                    @if ($errors->any())
                        <div class="alert alert-danger mb-3 py-2 px-3 rounded-3" style="font-size:0.85rem">
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Link to Event *</label>
                            <select name="event_id" id="event_select" class="form-select" required onchange="toggleCustomFields()">
                                <option value="">-- Select your event --</option>
                                @foreach($myEvents as $ev)
                                    <option value="{{ $ev->id }}" data-equipment='@json($ev->equipmentRequests->map(fn($item) => ["item_name" => $item->item_name, "quantity" => $item->quantity, "purpose" => $item->purpose])->values())'>{{ $ev->title }} ({{ $ev->event_date->format('M d') }})</option>
                                @endforeach
                                <option value="custom">Not on the list (Custom Event)</option>
                            </select>
                            <input type="text" name="event_title" id="custom_event_title" class="form-control mt-2" placeholder="Enter custom event title" style="display:none;" disabled>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label fw-bold d-block">Select Venue / Rooms * <span class="text-muted small fw-normal">(Select one or more rooms)</span></label>
                            
                            <div class="card border border-light shadow-sm p-3 bg-light-subtle rounded-3">
                                <ul class="nav nav-pills nav-fill mb-3" id="roomPickerTabs" role="tablist" style="font-size: 0.8rem;">
                                    <li class="nav-item">
                                        <button class="nav-link active py-2" id="tab-special-fac" data-bs-toggle="pill" data-bs-target="#pane-special-fac" type="button" role="tab">Facilities</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link py-2" id="tab-floor-4" data-bs-toggle="pill" data-bs-target="#pane-floor-4" type="button" role="tab">4th Floor</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link py-2" id="tab-floor-5" data-bs-toggle="pill" data-bs-target="#pane-floor-5" type="button" role="tab">5th Floor</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link py-2" id="tab-floor-6" data-bs-toggle="pill" data-bs-target="#pane-floor-6" type="button" role="tab">6th Floor</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link py-2" id="tab-floor-7-8" data-bs-toggle="pill" data-bs-target="#pane-floor-7-8" type="button" role="tab">7-8th Flr</button>
                                    </li>
                                </ul>
                                
                                <div class="tab-content" id="roomPickerTabsContent" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                                    <!-- Special Facilities Pane -->
                                    <div class="tab-pane fade show active" id="pane-special-fac" role="tabpanel">
                                        <div class="row row-cols-1 row-cols-md-2 g-2">
                                            @foreach(['NU Clark Gymnasium', 'NU Clark Auditorium', 'NU Clark Library', 'Mini Chapel'] as $room)
                                            <div class="col">
                                                <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                <label class="btn btn-outline-primary w-100 text-start py-2 px-3 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}">
                                                    <span><i class="bi bi-building me-2"></i>{{ $room }}</span>
                                                    <i class="bi bi-check-circle-fill check-icon d-none"></i>
                                                </label>
                                            </div>
                                            @endforeach
                                            
                                            <!-- Custom / Other Option -->
                                            <div class="col-12 mt-2">
                                                <input type="checkbox" id="chk_modal_custom" class="btn-check" autocomplete="off" onchange="toggleCustomVenueField()">
                                                <label class="btn btn-outline-warning w-100 text-start py-2 px-3 d-flex justify-content-between align-items-center" for="chk_modal_custom">
                                                    <span><i class="bi bi-question-circle me-2"></i>Other (Custom Venue Name)</span>
                                                    <i class="bi bi-check-circle-fill check-icon d-none"></i>
                                                </label>
                                                <input type="text" name="rooms[]" id="custom_venue_name_input" class="form-control mt-2" placeholder="Enter custom venue name" style="display:none;" disabled>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 4th Floor Pane -->
                                    <div class="tab-pane fade" id="pane-floor-4" role="tabpanel">
                                        <div class="row row-cols-2 row-cols-md-4 g-2">
                                            @for($i = 1; $i <= 23; $i++)
                                                @php $room = "Room 4" . sprintf('%02d', $i); @endphp
                                                <div class="col">
                                                    <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                    <label class="btn btn-outline-primary w-100 text-center py-2 px-1 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}" style="font-size:0.8rem;">
                                                        <span class="mx-auto">{{ $room }}</span>
                                                        <i class="bi bi-check-circle-fill check-icon d-none me-1"></i>
                                                    </label>
                                                </div>
                                            @endfor
                                        </div>
                                    </div>

                                    <!-- 5th Floor Pane -->
                                    <div class="tab-pane fade" id="pane-floor-5" role="tabpanel">
                                        <div class="row row-cols-2 row-cols-md-4 g-2">
                                            @for($i = 1; $i <= 23; $i++)
                                                @php $room = "Room 5" . sprintf('%02d', $i); @endphp
                                                <div class="col">
                                                    <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                    <label class="btn btn-outline-primary w-100 text-center py-2 px-1 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}" style="font-size:0.8rem;">
                                                        <span class="mx-auto">{{ $room }}</span>
                                                        <i class="bi bi-check-circle-fill check-icon d-none me-1"></i>
                                                    </label>
                                                </div>
                                            @endfor
                                        </div>
                                    </div>

                                    <!-- 6th Floor Pane -->
                                    <div class="tab-pane fade" id="pane-floor-6" role="tabpanel">
                                        <div class="row row-cols-2 row-cols-md-4 g-2">
                                            @for($i = 1; $i <= 23; $i++)
                                                @php $room = "Room 6" . sprintf('%02d', $i); @endphp
                                                <div class="col">
                                                    <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                    <label class="btn btn-outline-primary w-100 text-center py-2 px-1 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}" style="font-size:0.8rem;">
                                                        <span class="mx-auto">{{ $room }}</span>
                                                        <i class="bi bi-check-circle-fill check-icon d-none me-1"></i>
                                                    </label>
                                                </div>
                                            @endfor
                                        </div>
                                    </div>

                                    <!-- 7th & 8th Floor Pane -->
                                    <div class="tab-pane fade" id="pane-floor-7-8" role="tabpanel">
                                        <div class="fw-bold small text-muted mb-2" style="font-size:0.75rem;">7th Floor Classrooms</div>
                                        <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
                                            @for($i = 1; $i <= 23; $i++)
                                                @php $room = "Room 7" . sprintf('%02d', $i); @endphp
                                                <div class="col">
                                                    <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                    <label class="btn btn-outline-primary w-100 text-center py-2 px-1 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}" style="font-size:0.8rem;">
                                                        <span class="mx-auto">{{ $room }}</span>
                                                        <i class="bi bi-check-circle-fill check-icon d-none me-1"></i>
                                                    </label>
                                                </div>
                                            @endfor
                                        </div>
                                        <div class="fw-bold small text-muted mb-2" style="font-size:0.75rem;">8th Floor Classrooms</div>
                                        <div class="row row-cols-2 row-cols-md-4 g-2">
                                            @for($i = 1; $i <= 23; $i++)
                                                @php $room = "Room 8" . sprintf('%02d', $i); @endphp
                                                <div class="col">
                                                    <input type="checkbox" name="rooms[]" value="{{ $room }}" id="chk_modal_{{ Str::slug($room) }}" class="btn-check room-checkbox" autocomplete="off">
                                                    <label class="btn btn-outline-primary w-100 text-center py-2 px-1 d-flex justify-content-between align-items-center" for="chk_modal_{{ Str::slug($room) }}" style="font-size:0.8rem;">
                                                        <span class="mx-auto">{{ $room }}</span>
                                                        <i class="bi bi-check-circle-fill check-icon d-none me-1"></i>
                                                    </label>
                                                </div>
                                            @endfor
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Reserved Date *</label>
                            <input type="date" name="reserved_date" class="form-control" min="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Time *</label>
                            <input type="time" name="start_time" class="form-control" min="08:00" max="22:00" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">End Time *</label>
                            <input type="time" name="end_time" class="form-control" min="08:00" max="22:00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expected Attendees</label>
                            <input type="number" name="expected_attendees" class="form-control" min="1" value="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Purpose / Notes</label>
                            <textarea name="purpose" class="form-control" rows="1" placeholder="Brief description…"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Equipment Request Checklist</label>
                            <p class="small text-muted mb-2">The requester and every signatory will see these items on the permission form.</p>
                            @for($equipmentIndex = 0; $equipmentIndex < 3; $equipmentIndex++)
                                <div class="department-equipment-row row g-2 mb-2">
                                    <div class="col-md-5"><input data-field="item_name" type="text" name="equipment_items[{{ $equipmentIndex }}][item_name]" class="form-control" maxlength="150" placeholder="Equipment / item"></div>
                                    <div class="col-md-2"><input data-field="quantity" type="number" name="equipment_items[{{ $equipmentIndex }}][quantity]" class="form-control" min="1" max="10000" value="1" aria-label="Quantity"></div>
                                    <div class="col-md-5"><input data-field="purpose" type="text" name="equipment_items[{{ $equipmentIndex }}][purpose]" class="form-control" maxlength="255" placeholder="Purpose / setup note"></div>
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-nu-blue fw-700"><i class="bi bi-send me-1"></i>Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .transition-all { transition: all 0.3s ease; }
    .progress-line-fill { transition: width 0.8s ease-in-out; }
    .step-icon { border-style: solid; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    .reservation-track-card:hover { border-color: var(--nu-blue) !important; }
    .venue-card {
        background: white;
        border: 1px solid var(--gray-200);
        padding: 1.2rem;
        border-radius: 12px;
        transition: all 0.3s ease;
    }
    .venue-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        border-color: var(--nu-blue);
    }
    .cursor-pointer { cursor: pointer; }
    .matrix-card {
        transition: all 0.2s ease;
        min-height: 52px;
    }
    .matrix-card.available:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(25, 135, 84, 0.15);
        border-color: #198754 !important;
    }
    .matrix-card.occupied {
        background-color: #f8d7da !important;
        border-color: #f5c2c7 !important;
        color: #842029 !important;
        opacity: 0.8;
    }
    .disabled-occupied {
        background-color: #f8d7da !important;
        border-color: #f5c2c7 !important;
        color: #842029 !important;
        opacity: 0.7;
        cursor: not-allowed;
        pointer-events: none;
        text-decoration: line-through;
    }
    .check-icon {
        font-size: 0.8rem;
    }
</style>
@endsection
