@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-2 col-md-3 bg-white border-end shadow-sm p-0 min-vh-100">
            @include('layouts.partials.sidebar-admin')
        </div>

        <!-- Main Content -->
        <div class="col-lg-10 col-md-9 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center">
                    <a href="{{ route('admin.financial') }}" class="btn btn-sm btn-outline-secondary me-3">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <h3 class="fw-bold mb-0" style="color: var(--nu-blue);">Budget: {{ $event->title }}</h3>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="nu-card stat-card p-3 border-start border-4 border-primary shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Total Estimated</div>
                        <div class="stat-value fs-4 fw-bold" style="color: var(--nu-blue);">₱{{ number_format($totals['total_estimated'] ?? 0, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="nu-card stat-card p-3 border-start border-4 border-warning shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Total Actual</div>
                        <div class="stat-value fs-4 fw-bold text-warning">₱{{ number_format($totals['total_actual'] ?? 0, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    @php
                        $variance = $totals['variance'] ?? 0;
                        $varianceColor = $variance >= 0 ? 'text-success' : 'text-danger';
                        $varianceBorder = $variance >= 0 ? 'border-success' : 'border-danger';
                    @endphp
                    <div class="nu-card stat-card p-3 border-start border-4 {{ $varianceBorder }} shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Variance</div>
                        <div class="stat-value fs-4 fw-bold {{ $varianceColor }}">₱{{ number_format($variance, 2) }}</div>
                    </div>
                </div>
            </div>

            <!-- Add Form & Table Row -->
            <div class="row">
                <!-- Add Budget Item Form -->
                <div class="col-lg-4 mb-4">
                    <div class="nu-card card shadow-sm">
                        <div class="card-header bg-white fw-bold py-3">
                            <i class="bi bi-plus-circle me-1"></i> Add Budget Item
                        </div>
                        <div class="card-body">
                            <form action="{{ route('admin.event.budget.store', $event->id) }}" method="POST">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                    <select name="category" class="form-select @error('category') is-invalid @enderror" required>
                                        <option value="">Select Category</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category }}" {{ old('category') == $category ? 'selected' : '' }}>{{ $category }}</option>
                                        @endforeach
                                    </select>
                                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                                    <input type="text" name="description" class="form-control @error('description') is-invalid @enderror" value="{{ old('description') }}" required>
                                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-semibold">Estimated Amount <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" min="0" max="9999999999.99" name="estimated_amount" class="form-control @error('estimated_amount') is-invalid @enderror" value="{{ old('estimated_amount') }}" required>
                                        </div>
                                        @error('estimated_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-semibold">Actual Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" min="0" max="9999999999.99" name="actual_amount" class="form-control @error('actual_amount') is-invalid @enderror" value="{{ old('actual_amount') }}">
                                        </div>
                                        @error('actual_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                        <option value="planned" {{ old('status') == 'planned' ? 'selected' : '' }}>Planned</option>
                                        <option value="approved" {{ old('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                                        <option value="spent" {{ old('status') == 'spent' ? 'selected' : '' }}>Spent</option>
                                        <option value="cancelled" {{ old('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                    </select>
                                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-nu-blue" style="background-color: var(--nu-blue); color: white;">Add Item</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Budget Table -->
                <div class="col-lg-8 mb-4">
                    <div class="nu-card card shadow-sm">
                        <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-list-ul me-1"></i> Budget Items</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover nu-table mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th class="text-end">Estimated</th>
                                            <th class="text-end">Actual</th>
                                            <th class="text-end">Variance</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($budgetItems as $item)
                                            @php
                                                $itemVar = $item->estimated_amount - $item->actual_amount;
                                                $itemVarColor = $itemVar >= 0 ? 'text-success' : 'text-danger';
                                                
                                                $badgeClass = match($item->status) {
                                                    'planned' => 'bg-primary',
                                                    'approved' => 'bg-success',
                                                    'spent' => 'bg-warning text-dark',
                                                    'cancelled' => 'bg-secondary',
                                                    default => 'bg-secondary'
                                                };
                                            @endphp
                                            <tr>
                                                <td><span class="badge badge-category bg-light text-dark border">{{ $item->category }}</span></td>
                                                <td>{{ $item->description }}</td>
                                                <td class="text-end">₱{{ number_format($item->estimated_amount, 2) }}</td>
                                                <td class="text-end">₱{{ number_format($item->actual_amount, 2) }}</td>
                                                <td class="text-end fw-bold {{ $itemVarColor }}">₱{{ number_format($itemVar, 2) }}</td>
                                                <td class="text-center">
                                                    <span class="badge {{ $badgeClass }}">{{ ucfirst($item->status) }}</span>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal{{ $item->id }}">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form action="{{ route('admin.budget.delete', $item->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal{{ $item->id }}" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form action="{{ route('admin.budget.update', $item->id) }}" method="POST">
                                                            @csrf
                                                            @method('PUT')
                                                            <div class="modal-header bg-light">
                                                                <h5 class="modal-title">Edit Budget Item</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body text-start">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Category</label>
                                                                    <select name="category" class="form-select" required>
                                                                        @foreach($categories as $category)
                                                                            <option value="{{ $category }}" {{ $item->category == $category ? 'selected' : '' }}>{{ $category }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Description</label>
                                                                    <input type="text" name="description" class="form-control" value="{{ $item->description }}" required>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label fw-semibold">Estimated Amount</label>
                                                                        <div class="input-group">
                                                                            <span class="input-group-text">₱</span>
                                                                            <input type="number" step="0.01" min="0" max="9999999999.99" name="estimated_amount" class="form-control" value="{{ $item->estimated_amount }}" required>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <label class="form-label fw-semibold">Actual Amount</label>
                                                                        <div class="input-group">
                                                                            <span class="input-group-text">₱</span>
                                                                            <input type="number" step="0.01" min="0" max="9999999999.99" name="actual_amount" class="form-control" value="{{ $item->actual_amount }}">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Status</label>
                                                                    <select name="status" class="form-select" required>
                                                                        <option value="planned" {{ $item->status == 'planned' ? 'selected' : '' }}>Planned</option>
                                                                        <option value="approved" {{ $item->status == 'approved' ? 'selected' : '' }}>Approved</option>
                                                                        <option value="spent" {{ $item->status == 'spent' ? 'selected' : '' }}>Spent</option>
                                                                        <option value="cancelled" {{ $item->status == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-nu-blue" style="background-color: var(--nu-blue); color: white;">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">
                                                    No budget items recorded.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
