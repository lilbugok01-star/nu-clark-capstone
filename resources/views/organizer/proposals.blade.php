@extends('layouts.app')

@section('title', 'Event Proposals')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3 mb-0 text-gray-800"><i class="bi bi-file-earmark-text text-primary me-2"></i>Event Proposals</h2>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="card nu-card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table nu-table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Proposal #</th>
                        <th>Event Title</th>
                        <th>Prepared By</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($proposals as $proposal)
                        <tr>
                            <td class="ps-4 fw-medium text-dark">{{ $proposal->proposal_number }}</td>
                            <td>{{ $proposal->event->title ?? 'N/A' }}</td>
                            <td>{{ $proposal->preparedBy->full_name ?? 'N/A' }}</td>
                            <td>
                                @php
                                    $badgeClass = match($proposal->status) {
                                        'draft' => 'bg-secondary',
                                        'submitted' => 'bg-primary',
                                        'approved' => 'bg-success',
                                        'rejected' => 'bg-danger',
                                        default => 'bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }} rounded-pill px-3">{{ ucfirst($proposal->status) }}</span>
                            </td>
                            <td>{{ $proposal->created_at->format('M d, Y h:i A') }}</td>
                            <td class="text-end pe-4">
                                <a href="{{ route('proposal.show', $proposal->id) }}" class="btn btn-sm btn-outline-primary" title="View Details">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <a href="{{ route('proposal.export-pdf', $proposal->id) }}" class="btn btn-sm btn-outline-danger ms-1" title="Export PDF">
                                    <i class="bi bi-file-earmark-pdf"></i> PDF
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No event proposals found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($proposals->hasPages())
        <div class="card-footer bg-white border-0 py-3">
            {{ $proposals->links() }}
        </div>
    @endif
</div>
@endsection
