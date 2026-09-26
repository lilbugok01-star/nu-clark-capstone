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
                    <h3 class="fw-bold mb-0" style="color: var(--nu-blue);">Payments: {{ $event->title }}</h3>
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
                    <div class="nu-card stat-card p-3 border-start border-4 border-success shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Total Income</div>
                        <div class="stat-value fs-4 fw-bold text-success">₱{{ number_format($totals['total_income'] ?? 0, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="nu-card stat-card p-3 border-start border-4 border-danger shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Total Expenses</div>
                        <div class="stat-value fs-4 fw-bold text-danger">₱{{ number_format($totals['total_expenses'] ?? 0, 2) }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    @php
                        $net = $totals['net'] ?? 0;
                        $netColor = $net >= 0 ? 'color: var(--nu-gold);' : 'color: red;';
                    @endphp
                    <div class="nu-card stat-card p-3 border-start border-4 border-warning shadow-sm h-100">
                        <div class="stat-label text-muted small fw-bold text-uppercase">Net Profit/Loss</div>
                        <div class="stat-value fs-4 fw-bold" style="{{ $netColor }}">₱{{ number_format($net, 2) }}</div>
                    </div>
                </div>
            </div>

            <!-- Add Form & Table Row -->
            <div class="row">
                <!-- Record Payment Form -->
                <div class="col-lg-4 mb-4">
                    <div class="nu-card card shadow-sm">
                        <div class="card-header bg-white fw-bold py-3">
                            <i class="bi bi-plus-circle me-1"></i> Record Transaction
                        </div>
                        <div class="card-body">
                            <form action="{{ route('admin.event.payments.store', $event->id) }}" method="POST" enctype="multipart/form-data">
                                @csrf
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold d-block">Transaction Type <span class="text-danger">*</span></label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="payment_type" id="typeIncome" value="income" {{ old('payment_type', 'income') == 'income' ? 'checked' : '' }} required>
                                        <label class="form-check-label text-success fw-bold" for="typeIncome">Income</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="payment_type" id="typeExpense" value="expense" {{ old('payment_type') == 'expense' ? 'checked' : '' }} required>
                                        <label class="form-check-label text-danger fw-bold" for="typeExpense">Expense</label>
                                    </div>
                                    @error('payment_type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" min="0.01" max="9999999999.99" name="amount" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                                    </div>
                                    @error('amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                                    <input type="text" name="description" class="form-control @error('description') is-invalid @enderror" value="{{ old('description') }}" required>
                                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                                    <select name="payment_method" class="form-select @error('payment_method') is-invalid @enderror" required>
                                        <option value="">Select Method</option>
                                        @foreach($paymentMethods ?? ['Cash', 'Bank Transfer', 'Check', 'GCash', 'Other'] as $method)
                                            <option value="{{ $method }}" {{ old('payment_method') == $method ? 'selected' : '' }}>{{ $method }}</option>
                                        @endforeach
                                    </select>
                                    @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" name="payment_date" class="form-control @error('payment_date') is-invalid @enderror" value="{{ old('payment_date', date('Y-m-d')) }}" required>
                                    @error('payment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Reference Number</label>
                                    <input type="text" name="reference_number" class="form-control @error('reference_number') is-invalid @enderror" value="{{ old('reference_number') }}">
                                    @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Receipt / Proof (Optional)</label>
                                    <input type="file" name="receipt" class="form-control @error('receipt') is-invalid @enderror" accept="image/*,.pdf">
                                    @error('receipt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Notes</label>
                                    <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes') }}</textarea>
                                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-nu-blue" style="background-color: var(--nu-blue); color: white;">Record Transaction</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Payments Table -->
                <div class="col-lg-8 mb-4">
                    <div class="nu-card card shadow-sm">
                        <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-list-ul me-1"></i> Transaction History</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover nu-table mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Recorded By</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($payments as $payment)
                                            @php
                                                $isIncome = $payment->payment_type === 'income';
                                                $badgeClass = $isIncome ? 'bg-success' : 'bg-danger';
                                                $amountColor = $isIncome ? 'text-success' : 'text-danger';
                                                $sign = $isIncome ? '+' : '-';
                                            @endphp
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($payment->payment_date)->format('M d, Y') }}</td>
                                                <td><span class="badge {{ $badgeClass }}">{{ ucfirst($payment->payment_type) }}</span></td>
                                                <td>
                                                    {{ $payment->description }}
                                                    @if($payment->receipt_path)
                                                        <a href="{{ \App\Helpers\StorageUrl::url($payment->receipt_path) }}" target="_blank" rel="noopener" class="ms-1" title="View Receipt"><i class="bi bi-paperclip"></i></a>
                                                    @endif
                                                </td>
                                                <td class="text-end fw-bold {{ $amountColor }}">{{ $sign }} ₱{{ number_format($payment->amount, 2) }}</td>
                                                <td>{{ $payment->payment_method }}</td>
                                                <td>{{ $payment->reference_number ?? '-' }}</td>
                                                <td>{{ $payment->recorder->name ?? 'System' }}</td>
                                                <td class="text-end">
                                                    <form action="{{ route('admin.payment.delete', $payment->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this transaction?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">
                                                    No transactions recorded.
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
