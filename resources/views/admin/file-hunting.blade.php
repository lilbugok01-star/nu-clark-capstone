@extends('layouts.app')
@section('title', 'File Hunting & Signatories')
@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-2 col-md-3">
            @include('layouts.partials.sidebar-admin')
        </div>
        <div class="col-lg-10 col-md-9">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h4 style="color:var(--nu-blue);font-weight:800;margin:0">
                    <i class="bi bi-file-earmark-person"></i> File Hunting Configuration
                </h4>
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="addSignatory()">
                    <i class="bi bi-plus-circle me-1"></i> Add Signatory
                </button>
            </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">
                        Configure the sequence of authorized personnel for approving documents (e.g., Venue Reservations).
                        The order defined here determines the workflow path. Changes apply to newly submitted documents.
                    </p>

                    <form action="{{ route('admin.file-hunting.save') }}" method="POST" id="signatoriesForm" autocomplete="off">
                        @csrf
                        <div id="signatoriesContainer">
                            @foreach($signatories as $index => $sig)
                                <div class="signatory-row bg-light border rounded-3 p-3 mb-3 d-flex gap-3 align-items-center" data-index="{{ $index }}">
                                    <div class="flex-shrink-0 fw-bold fs-5 text-secondary" style="width: 30px;">
                                        #<span class="step-number">{{ $index + 1 }}</span>
                                    </div>
                                    <div class="flex-grow-1 row g-2">
                                        <div class="col-md-6">
                                            <label class="small text-muted fw-bold">System Role</label>
                                            <select name="signatories[{{ $index }}][role]" class="form-select form-select-sm fw-bold" required>
                                                @foreach($availableRoles as $roleKey => $roleLabel)
                                                    <option value="{{ $roleKey }}" {{ $sig->role === $roleKey ? 'selected' : '' }}>
                                                        {{ $roleLabel }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small text-muted fw-bold">Position Label (Display)</label>
                                            <input type="text" name="signatories[{{ $index }}][position_label]" class="form-control form-select-sm fw-bold" value="{{ $sig->position_label }}" required>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-2 align-items-center ms-2 border-start ps-3">
                                        <div class="form-check form-switch mb-0" title="Active">
                                            <input class="form-check-input" type="checkbox" name="signatories[{{ $index }}][is_active]" value="1" {{ $sig->is_active ? 'checked' : '' }}>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeSignatory(this)" title="Remove">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-light rounded-pill px-4 me-2" onclick="cancelSignatoryChanges()">Cancel</button>
                            <button type="submit" class="btn btn-primary fw-bold rounded-pill px-5">Save Configuration</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 bg-primary bg-opacity-10 rounded-4">
                    <h5 class="fw-bold mb-3 text-primary"><i class="bi bi-info-circle"></i> How it works</h5>
                    <ul class="small text-secondary mb-0 ps-3 lh-lg">
                        <li><strong>Step Order:</strong> Documents move sequentially from step #1 to the last active step.</li>
                        <li><strong>System Role:</strong> Defines the user group allowed to act at this step. Ensure users are assigned these roles in the System Users tab.</li>
                        <li><strong>Position Label:</strong> The Title displayed to users in the File Hunting tracker.</li>
                        <li><strong>Active:</strong> Toggle whether this step is currently required.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>

<!-- Template for new signatory -->
<template id="signatoryTemplate">
    <div class="signatory-row bg-light border rounded-3 p-3 mb-3 d-flex gap-3 align-items-center" data-index="__INDEX__">
        <div class="flex-shrink-0 fw-bold fs-5 text-secondary" style="width: 30px;">
            #<span class="step-number">__STEP__</span>
        </div>
        <div class="flex-grow-1 row g-2">
            <div class="col-md-6">
                <label class="small text-muted fw-bold">System Role</label>
                <select name="signatories[__INDEX__][role]" class="form-select form-select-sm fw-bold" required>
                    <option value="student_development">Student Development Officer</option>
                    <option value="program_chair">Program Chair</option>
                    <option value="dean">College Dean</option>
                    <option value="executive_director">Executive Director</option>
                    <option value="adviser">Adviser</option>
                    <option value="department_head">Department Head</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="small text-muted fw-bold">Position Label (Display)</label>
                <input type="text" name="signatories[__INDEX__][position_label]" class="form-control form-select-sm fw-bold" required placeholder="e.g. Program Chair">
            </div>
        </div>
        <div class="d-flex flex-column gap-2 align-items-center ms-2 border-start ps-3">
            <div class="form-check form-switch mb-0" title="Active">
                <input class="form-check-input" type="checkbox" name="signatories[__INDEX__][is_active]" value="1" checked>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removeSignatory(this)" title="Remove">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>

@push('scripts')
<script>
    // Restore the saved rows as well as their values; a form reset alone cannot undo added or deleted rows.
    const savedSignatoriesHtml = document.getElementById('signatoriesContainer').innerHTML;

    function cancelSignatoryChanges() {
        document.getElementById('signatoriesContainer').innerHTML = savedSignatoriesHtml;
        updateStepNumbers();
    }

    function updateStepNumbers() {
        const rows = document.querySelectorAll('#signatoriesContainer .signatory-row');
        rows.forEach((row, idx) => {
            row.querySelector('.step-number').textContent = idx + 1;
            
            // Update input names to maintain correct array indices
            const select = row.querySelector('select');
            const input = row.querySelector('input[type="text"]');
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            select.name = `signatories[${idx}][role]`;
            input.name = `signatories[${idx}][position_label]`;
            checkbox.name = `signatories[${idx}][is_active]`;
            row.dataset.index = idx;
        });
    }

    function addSignatory() {
        const container = document.getElementById('signatoriesContainer');
        const template = document.getElementById('signatoryTemplate').innerHTML;
        const newIndex = container.children.length;
        
        let html = template.replace(/__INDEX__/g, newIndex)
                           .replace(/__STEP__/g, newIndex + 1);
                           
        container.insertAdjacentHTML('beforeend', html);
    }

    function removeSignatory(btn) {
        if(confirm('Remove this step from the signing chain?')) {
            btn.closest('.signatory-row').remove();
            updateStepNumbers();
        }
    }
</script>
@endpush
@endsection
