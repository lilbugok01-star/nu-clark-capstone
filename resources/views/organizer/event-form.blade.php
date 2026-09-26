@extends('layouts.app')
@section('title', ($event ? 'Edit Event' : 'Create New Event'))
@section('content')
<div class="container py-5" style="max-width:820px">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('organizer.events') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h4 class="fw-800 mb-0" style="color:var(--nu-blue)">
                <i class="bi bi-{{ $event ? 'pencil-square' : 'calendar-plus' }} me-2" style="color:var(--nu-gold)"></i>
                {{ $event ? 'Edit Event' : 'Create New Event' }}
            </h4>
            <p class="text-muted small mb-0">{{ $event ? 'Update event details and requested equipment' : 'Create the event request, then complete its proposal for approval' }}</p>
        </div>
    </div>

    <form method="POST"
          action="{{ $event ? route('organizer.event.update', $event->id) : route('organizer.event.store') }}"
          enctype="multipart/form-data">
        @csrf
        @if($event) @method('PUT') @endif

        @if($errors->any())
        <div class="alert alert-danger rounded-3 mb-4">
            <i class="bi bi-exclamation-circle me-1"></i>
            Please fix the following errors:
            <ul class="mb-0 mt-1 small">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif



        <div class="row g-4">
            <!-- Event Details Card -->
            <div class="col-12">
                <div class="nu-card p-4">
                    <h6 class="fw-700 mb-3" style="color:var(--nu-blue)"><i class="bi bi-info-circle me-2" style="color:var(--nu-gold)"></i>Event Information</h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Event Title *</label>
                            <input type="text" name="title" class="form-control" required
                                   value="{{ old('title', $event->title ?? '') }}" placeholder="e.g. IT Summit 2026">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="4"
                                      placeholder="Describe your event…">{{ old('description', $event->description ?? '') }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="">-- Select Category --</option>
                                @php
                                    $dbCategories = \Illuminate\Support\Facades\Schema::hasTable('event_categories') 
                                        ? \App\Models\EventCategory::active()->pluck('name')->toArray() 
                                        : [];
                                    $categories = !empty($dbCategories) 
                                        ? $dbCategories 
                                        : ['Academic','Social','Sports','Cultural','Leadership','Technology','Seminar','Workshop','Competition','Other'];
                                @endphp
                                @foreach($categories as $cat)
                                    <option value="{{ $cat }}" {{ old('category', $event->category ?? '') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Capacity (Max attendees) *</label>
                            <input type="number" name="capacity" class="form-control" min="1" required
                                   value="{{ old('capacity', $event->capacity ?? 100) }}">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Venue Card -->
            <div class="col-12">
                <div class="nu-card p-4">
                    <h6 class="fw-700 mb-3" style="color:var(--nu-blue)"><i class="bi bi-building me-2" style="color:var(--nu-gold)"></i>Venue & Schedule</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Venue Type / Room *</label>
                            <select name="venue_type" class="form-select" id="venueTypeSelect" required onchange="updateVenueText()">
                                <option value="">-- Select Venue --</option>
                                @foreach(\App\Models\VenueReservation::venueNames() as $vn)
                                    @if($vn !== 'Other')
                                    <option value="{{ $vn }}" {{ old('venue_type', $event->venue_type ?? '') === $vn ? 'selected' : '' }}>{{ $vn }}</option>
                                    @endif
                                @endforeach
                                <option value="Other" {{ old('venue_type', $event->venue_type ?? '') === 'Other' ? 'selected' : '' }}>Other (Custom Name)</option>
                            </select>
                            <input type="text" name="custom_venue_type" class="form-control mt-2" id="customVenueType"
                                   placeholder="Enter custom venue/room name" style="display:none;"
                                   value="{{ old('custom_venue_type', '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Venue / Location *</label>
                            <input type="text" name="venue" class="form-control" id="venueText" required
                                   value="{{ old('venue', $event->venue ?? '') }}" placeholder="e.g. NU Clark Gymnasium, 2nd Floor">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Event Date *</label>
                            <input type="date" name="event_date" class="form-control" required
                                   min="{{ now()->toDateString() }}"
                                   value="{{ old('event_date', $event ? $event->event_date->format('Y-m-d') : '') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" id="startTimeInput" class="form-control" required
                                   value="{{ old('start_time', $event ? substr($event->start_time,0,5) : '') }}" onchange="handleStartTimeChange()">
                            <div class="form-text text-muted" style="font-size:0.75rem;"><i class="bi bi-info-circle me-1"></i>Min. 1 hr event duration</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Time *</label>
                            <input type="time" name="end_time" id="endTimeInput" class="form-control" required
                                   value="{{ old('end_time', $event ? substr($event->end_time,0,5) : '') }}">
                            <div class="form-text text-muted" style="font-size:0.75rem;"><i class="bi bi-clock me-1"></i>Must be ≥ 1 hr after start</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Equipment Request Card -->
            <div class="col-12">
                <div class="nu-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div><h6 class="fw-700 mb-1" style="color:var(--nu-blue)"><i class="bi bi-tools me-2" style="color:var(--nu-gold)"></i>Equipment Request</h6><small class="text-muted">This checklist appears in the proposal and the signatories' evidence.</small></div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addEquipment"><i class="bi bi-plus-circle me-1"></i>Add item</button>
                    </div>
                    @php
                        $equipmentRows = old('equipment_items', $event ? $event->equipmentRequests->map(fn($item) => ['item_name' => $item->item_name, 'quantity' => $item->quantity, 'purpose' => $item->purpose])->values()->all() : [[]]);
                        if (empty($equipmentRows)) $equipmentRows = [[]];
                    @endphp
                    <div id="equipmentRows" class="d-grid gap-2">
                        @foreach($equipmentRows as $index => $item)
                        <div class="equipment-row row g-2 align-items-end border rounded-3 p-2" data-index="{{ $index }}">
                            <div class="col-md-5"><label class="form-label small fw-bold">Equipment / item</label><input type="text" name="equipment_items[{{ $index }}][item_name]" class="form-control" maxlength="150" value="{{ $item['item_name'] ?? '' }}" placeholder="e.g. Projector"></div>
                            <div class="col-md-2"><label class="form-label small fw-bold">Quantity</label><input type="number" name="equipment_items[{{ $index }}][quantity]" class="form-control" min="1" max="10000" value="{{ $item['quantity'] ?? 1 }}"></div>
                            <div class="col-md-4"><label class="form-label small fw-bold">Purpose / setup note</label><input type="text" name="equipment_items[{{ $index }}][purpose]" class="form-control" maxlength="255" value="{{ $item['purpose'] ?? '' }}" placeholder="Where or how it will be used"></div>
                            <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-equipment" title="Remove item"><i class="bi bi-trash"></i></button></div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Poster & Settings Card -->
            <div class="col-12">
                <div class="nu-card p-4">
                    <h6 class="fw-700 mb-3" style="color:var(--nu-blue)"><i class="bi bi-image me-2" style="color:var(--nu-gold)"></i>Poster & Settings</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Event Poster / Cover Image</label>
                            @if($event && $event->poster_path)
                            <div class="mb-2">
                                <img src="{{ \App\Helpers\StorageUrl::url($event->poster_path) }}" class="rounded-2" style="height:80px;object-fit:cover">
                                <span class="text-muted small ms-2">Current poster (upload new to replace)</span>
                            </div>
                            @endif
                            <input type="file" name="poster" class="form-control" accept="image/*" onchange="previewPoster(event)">
                            <small class="text-muted">JPG, PNG, WEBP · Max 4MB</small>
                            <div id="posterPreview" class="mt-2 d-none">
                                <img id="posterImg" class="rounded-2 w-100" style="max-height:150px;object-fit:cover" alt="Preview">
                            </div>
                        </div>
                        @if($event && Auth::user()->role === 'admin')
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                @if(Auth::user()->role === 'admin')
                                    @foreach(['published','draft','cancelled','completed'] as $s)
                                        <option value="{{ $s }}" {{ ($event->status ?? 'published') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                                    @endforeach
                                @else
                                    @foreach(['published','cancelled'] as $s)
                                        <option value="{{ $s }}" {{ ($event->status ?? 'published') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>
                        @endif
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch pt-3">
                                <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" value="1"
                                       {{ old('is_featured', $event->is_featured ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label fw-600 small" for="is_featured">
                                    <i class="bi bi-star me-1" style="color:var(--nu-gold)"></i>Feature on Homepage
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-nu-blue px-5 fw-700">
                    <i class="bi bi-{{ $event ? 'save' : 'plus-circle' }} me-2"></i>
                    {{ $event ? 'Update Event Request' : 'Save Event & Continue to Proposal' }}
                </button>
                <a href="{{ route('organizer.events') }}" class="btn btn-outline-secondary px-4">Cancel</a>
            </div>
        </div>
    </form>
</div>
@endsection
@push('scripts')
<script>
function updateVenueText() {
    const sel = document.getElementById('venueTypeSelect');
    const txt = document.getElementById('venueText');
    const customInput = document.getElementById('customVenueType');

    if (sel.value === 'Other') {
        customInput.style.display = 'block';
        customInput.disabled = false;
        customInput.required = true;
        customInput.focus();
        // Clear venue text so user fills in custom
        if (!customInput.value) txt.value = '';
    } else {
        customInput.style.display = 'none';
        customInput.disabled = true;
        customInput.required = false;
        customInput.value = '';
        if (sel.value && !txt.value) txt.value = sel.value;
    }
}

// When custom venue input changes, sync to venue text
document.addEventListener('DOMContentLoaded', function() {
    const customInput = document.getElementById('customVenueType');
    if (customInput) {
        customInput.addEventListener('input', function() {
            document.getElementById('venueText').value = this.value;
        });
    }
    // Init: show custom input if 'Other' is pre-selected
    const sel = document.getElementById('venueTypeSelect');
    if (sel && sel.value === 'Other') {
        customInput.style.display = 'block';
        customInput.disabled = false;
        customInput.required = true;
    }
});

function previewPoster(e) {
    const f = e.target.files[0];
    if (!f) return;
    const r = new FileReader();
    r.onload = ev => {
        document.getElementById('posterImg').src = ev.target.result;
        document.getElementById('posterPreview').classList.remove('d-none');
    };
    r.readAsDataURL(f);
}

document.addEventListener('DOMContentLoaded', function() {
    const rows = document.getElementById('equipmentRows');
    const addButton = document.getElementById('addEquipment');
    if (!rows || !addButton) return;

    function bindRemoveButtons() {
        rows.querySelectorAll('.remove-equipment').forEach(button => {
            button.onclick = function() {
                if (rows.querySelectorAll('.equipment-row').length === 1) {
                    this.closest('.equipment-row').querySelectorAll('input[type="text"]').forEach(input => input.value = '');
                    this.closest('.equipment-row').querySelector('input[type="number"]').value = 1;
                    return;
                }
                this.closest('.equipment-row').remove();
            };
        });
    }

    addButton.addEventListener('click', function() {
        const index = Date.now();
        rows.insertAdjacentHTML('beforeend', `<div class="equipment-row row g-2 align-items-end border rounded-3 p-2" data-index="${index}"><div class="col-md-5"><label class="form-label small fw-bold">Equipment / item</label><input type="text" name="equipment_items[${index}][item_name]" class="form-control" maxlength="150" placeholder="e.g. Projector"></div><div class="col-md-2"><label class="form-label small fw-bold">Quantity</label><input type="number" name="equipment_items[${index}][quantity]" class="form-control" min="1" max="10000" value="1"></div><div class="col-md-4"><label class="form-label small fw-bold">Purpose / setup note</label><input type="text" name="equipment_items[${index}][purpose]" class="form-control" maxlength="255" placeholder="Where or how it will be used"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-equipment" title="Remove item"><i class="bi bi-trash"></i></button></div></div>`);
        bindRemoveButtons();
    });
    bindRemoveButtons();
});
function handleStartTimeChange() {
    const startInput = document.getElementById('startTimeInput');
    const endInput   = document.getElementById('endTimeInput');
    if (!startInput.value) return;

    const [hours, minutes] = startInput.value.split(':').map(Number);
    let endHours = hours + 1;
    let endMinutes = minutes;
    if (endHours >= 24) endHours = 23;

    const formattedEnd = String(endHours).padStart(2, '0') + ':' + String(endMinutes).padStart(2, '0');
    
    // If end time is empty or less than start + 1hr, set to start + 1hr
    if (!endInput.value || endInput.value <= startInput.value) {
        endInput.value = formattedEnd;
    } else {
        const [currentEndH, currentEndM] = endInput.value.split(':').map(Number);
        const durationMin = (currentEndH * 60 + currentEndM) - (hours * 60 + minutes);
        if (durationMin < 60) {
            endInput.value = formattedEnd;
        }
    }
}

// Client-side form submit check
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const startInput = document.getElementById('startTimeInput');
            const endInput   = document.getElementById('endTimeInput');
            if (startInput && endInput && startInput.value && endInput.value) {
                const [sh, sm] = startInput.value.split(':').map(Number);
                const [eh, em] = endInput.value.split(':').map(Number);
                const diff = (eh * 60 + em) - (sh * 60 + sm);
                if (diff < 60) {
                    e.preventDefault();
                    alert('Event duration must be at least 1 hour (60 minutes). Please adjust the End Time.');
                    endInput.focus();
                    return false;
                }
            }
        });
    }
});
</script>
@endpush
