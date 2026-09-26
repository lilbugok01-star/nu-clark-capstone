@extends('layouts.app')
@section('title', 'Previous Event Records')
@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-2 col-md-3">@include('layouts.partials.sidebar-admin')</div>
        <div class="col-lg-10 col-md-9">
            <div class="mb-4">
                <h4 class="fw-bold mb-1" style="color:var(--nu-blue)"><i class="bi bi-clock-history me-2"></i>Previous Event Records</h4>
                <p class="text-muted mb-0">Compare proposed events with real events that already happened. Values shown here are recorded results, not predictions.</p>
            </div>

            <div class="nu-card p-4 mb-4">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small">Year</label>
                        <select name="year" class="form-select">
                            <option value="">All years</option>
                            @foreach($years as $year)<option value="{{ $year }}" @selected((string) request('year') === (string) $year)>{{ $year }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold small">Category</label>
                        <select name="category" class="form-select">
                            <option value="">All categories</option>
                            @foreach($categories as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-nu-blue flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a href="{{ route('admin.previous-events.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <div class="nu-card table-responsive">
                <table class="table nu-table align-middle mb-0">
                    <thead><tr><th>Previous Event</th><th>Date / Venue</th><th class="text-center">Registered</th><th class="text-center">Attended</th><th class="text-center">Budget</th><th></th></tr></thead>
                    <tbody>
                    @forelse($events as $item)
                        <tr>
                            <td><div class="fw-bold" style="color:var(--nu-blue)">{{ $item->title }}</div><small class="text-muted">{{ $item->category ?: 'General' }}</small></td>
                            <td><div>{{ $item->event_date->format('M d, Y') }}</div><small class="text-muted">{{ $item->venue }}</small></td>
                            <td class="text-center">{{ $item->registrations_count }}</td>
                            <td class="text-center">{{ $item->attended_count }}</td>
                            <td class="text-center">PHP {{ number_format($item->budgetItems->sum('actual_amount'), 2) }}</td>
                            <td class="text-end"><a href="{{ route('admin.previous-events.compare', $item) }}" class="btn btn-sm btn-outline-primary">Find similar events</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">No previous events match these filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $events->links('pagination::bootstrap-5') }}</div>
        </div>
    </div>
</div>
@endsection
