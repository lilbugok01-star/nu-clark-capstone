@extends('layouts.app')
@section('title', 'My Events — Organizer')
@section('content')
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0" style="color:var(--nu-blue)"><i class="bi bi-calendar3 me-2"></i>My Events</h4>
        <a href="{{ route('organizer.event.create') }}" class="btn btn-gold"><i class="bi bi-plus me-1"></i>Create Event</a>
    </div>

    <!-- Tabs: Upcoming vs Past Events -->
    <ul class="nav nav-pills mb-4 gap-2">
        <li class="nav-item">
            <a class="nav-link {{ ($tab ?? 'upcoming') === 'upcoming' ? 'active' : '' }}"
               href="{{ route('organizer.events', ['tab' => 'upcoming']) }}"
               style="{{ ($tab ?? 'upcoming') === 'upcoming' ? 'background:var(--nu-blue);color:#fff;font-weight:700;' : 'background:var(--gray-100);color:var(--gray-700);font-weight:600;' }}">
                <i class="bi bi-calendar-check me-1"></i> Upcoming Events
                <span class="badge {{ ($tab ?? 'upcoming') === 'upcoming' ? 'bg-light text-dark' : 'bg-secondary text-white' }} ms-1">{{ $upcomingCount ?? 0 }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ ($tab ?? 'upcoming') === 'past' ? 'active' : '' }}"
               href="{{ route('organizer.events', ['tab' => 'past']) }}"
               style="{{ ($tab ?? 'upcoming') === 'past' ? 'background:var(--nu-blue);color:#fff;font-weight:700;' : 'background:var(--gray-100);color:var(--gray-700);font-weight:600;' }}">
                <i class="bi bi-clock-history me-1"></i> Past / Completed Events
                <span class="badge {{ ($tab ?? 'upcoming') === 'past' ? 'bg-light text-dark' : 'bg-secondary text-white' }} ms-1">{{ $pastCount ?? 0 }}</span>
            </a>
        </li>
    </ul>

    @if($events->count() > 0)
    <div class="row g-4 mb-4">
        @foreach($events as $event)
        <div class="col-md-6 col-lg-4 fade-in-up" style="animation-delay:{{ $loop->index * 0.05 }}s">
            <div class="event-card h-100">
                @if($event->poster_path)
                    <img src="{{ \App\Helpers\StorageUrl::url($event->poster_path) }}" class="event-card-img" alt="{{ $event->title }}">
                @else
                    <div class="event-card-img-placeholder">
                        <i class="bi bi-calendar-event text-white" style="font-size:3rem;opacity:.5"></i>
                    </div>
                @endif
                <div class="position-absolute" style="top:10px;right:10px">
                    @php
                        $statusLabel = match($event->status) {
                            'pending_adviser'    => 'Pending Approval',
                            'pending_dept_head'  => 'Pending Approval',
                            'pending_dean'       => 'Pending Approval',
                            'pending_director'   => 'Pending Approval',
                            'published'          => 'Published',
                            'draft'              => 'Draft',
                            'cancelled'          => 'Cancelled',
                            'completed'          => 'Completed',
                            'rejected'           => 'Rejected',
                            default              => ucfirst($event->status),
                        };
                        $statusBadgeClass = match(true) {
                            str_starts_with($event->status, 'pending_') => 'bg-warning text-dark',
                            $event->status === 'published'  => 'bg-success text-white',
                            $event->status === 'rejected'   => 'bg-danger text-white',
                            $event->status === 'cancelled'  => 'bg-secondary text-white',
                            $event->status === 'completed'  => 'bg-info text-dark',
                            default                         => 'bg-light text-dark',
                        };
                    @endphp
                    <span class="badge {{ $statusBadgeClass }} shadow-sm">{{ $statusLabel }}</span>
                </div>
                <div class="event-card-body">
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        @if($event->category)<span class="badge-category">{{ $event->category }}</span>@endif
                        @if($event->venue_type)<span class="venue-badge"><i class="bi bi-building me-1"></i>{{ $event->venue_type }}</span>@endif
                    </div>
                    <h6 class="fw-700 mb-2" style="color:var(--nu-blue)">{{ $event->title }}</h6>
                    <p class="text-muted small mb-2" style="flex-grow:1">{{ Str::limit($event->description, 70) }}</p>
                    <div class="mt-auto">
                        <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1 text-nu-blue"></i>{{ $event->venue }}</div>
                        <div class="small text-muted"><i class="bi bi-clock me-1 text-nu-blue"></i>{{ substr($event->start_time,0,5) }} – {{ substr($event->end_time,0,5) }}</div>
                    </div>
                </div>
                <div class="event-card-footer">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-700 small" style="color:var(--nu-blue)">{{ $event->event_date->format('M d, Y') }}</div>
                        <div class="text-muted" style="font-size:.72rem">
                            <i class="bi bi-people me-1"></i>{{ $event->registrations_count }}/{{ $event->capacity }} joined
                        </div>
                    </div>
                    <div class="d-flex gap-1 border-top pt-2 mt-2">
                        @if($event->latestProposal)
                            <a href="{{ route('proposal.show', $event->latestProposal) }}" class="btn btn-outline-primary btn-sm flex-fill" title="View proposal"><i class="bi bi-file-earmark-check"></i></a>
                        @else
                            <a href="{{ route('proposal.create', $event) }}" class="btn btn-primary btn-sm flex-fill" title="Create required proposal"><i class="bi bi-file-earmark-plus"></i></a>
                        @endif
                        <a href="{{ route('organizer.event.attendees', $event->id) }}" class="btn btn-outline-gold btn-sm flex-fill" title="Attendees"><i class="bi bi-people"></i></a>
                        <a href="{{ route('organizer.event.edit', $event->id) }}" class="btn btn-outline-secondary btn-sm flex-fill" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('organizer.event.delete', $event->id) }}" method="POST" onsubmit="return confirm('Delete this event?')" class="flex-fill d-flex">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    <div class="mt-3">{{ $events->links('pagination::bootstrap-5') }}</div>
    @else
    <div class="text-center py-5 nu-card">
        <div style="font-size:4rem">📅</div>
        <h5 class="text-muted mt-3">No events yet.</h5>
        <a href="{{ route('organizer.event.create') }}" class="btn btn-nu-blue mt-2">Create Your First Event</a>
    </div>
    @endif
</div>
@endsection
