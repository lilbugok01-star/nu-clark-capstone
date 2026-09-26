@extends('layouts.app')
@section('title', 'Previous Event Comparison')
@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-2 col-md-3">@include('layouts.partials.sidebar-admin')</div>
        <div class="col-lg-10 col-md-9">
            <a href="{{ route('admin.previous-events.index') }}" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Previous event records</a>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color:var(--nu-blue)">Comparison for {{ $event->title }}</h4>
                    <p class="text-muted mb-0">Matched by category, venue, and meaningful words in the title and description.</p>
                </div>
                @if(Auth::user()->role !== 'admin' || Auth::id() === $event->organizer_id)
                    <a href="{{ route('proposal.create', $event) }}" class="btn btn-nu-blue"><i class="bi bi-file-earmark-plus me-1"></i>Use in proposal</a>
                @endif
            </div>

            <div class="alert alert-info border-0 shadow-sm">
                <div class="fw-bold mb-2"><i class="bi bi-lightbulb me-1"></i>Recommendations to consider in the proposal</div>
                <ul class="mb-0">@foreach($recommendations as $recommendation)<li class="mb-1">{{ $recommendation }}</li>@endforeach</ul>
            </div>

            <div class="row g-3">
                @forelse($comparisons as $previous)
                    <div class="col-12">
                        <div class="nu-card p-4">
                            <div class="d-flex justify-content-between gap-3 flex-wrap mb-3">
                                <div><h5 class="fw-bold mb-1">{{ $previous->title }}</h5><div class="text-muted small">{{ $previous->event_date->format('M d, Y') }} · {{ $previous->venue }}</div></div>
                                <span class="badge bg-primary align-self-start">{{ $previous->comparison_score }}% match</span>
                            </div>
                            <div class="row g-3 text-center">
                                <div class="col-md-3"><small class="text-muted d-block">Registered</small><strong>{{ $previous->registrations_count }}</strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">Attended</small><strong>{{ $previous->attended_count }} ({{ $previous->attendance_rate }}%)</strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">Actual expenses</small><strong>PHP {{ number_format($previous->actual_expenses, 2) }}</strong></div>
                                <div class="col-md-3"><small class="text-muted d-block">Equipment items</small><strong>{{ $previous->equipmentRequests->count() }}</strong></div>
                            </div>
                            <div class="small text-muted mt-3">Matched because: {{ implode('; ', $previous->comparison_reasons) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="col-12"><div class="nu-card p-5 text-center text-muted">No sufficiently similar previous event was found.</div></div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
