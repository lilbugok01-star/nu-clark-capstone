<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Form - {{ $res->event?->title ?? $res->event_title }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; }
        .permission-form { background: white; padding: 50px; border-radius: 0; box-shadow: 0 0 20px rgba(0,0,0,0.1); max-width: 850px; margin: 30px auto; min-height: 1050px; position: relative; border: 1px solid #ddd; }
        .nu-header { border-bottom: 3px solid #003087; padding-bottom: 20px; margin-bottom: 30px; }
        .form-title { color: #003087; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .info-label { font-weight: 700; color: #555; width: 180px; display: inline-block; font-size: 0.9rem; }
        .info-value { font-weight: 600; color: #222; border-bottom: 2px solid #ccc; display: inline-block; width: calc(100% - 190px); padding-bottom: 2px; }
        .signature-box { border-bottom: 2px solid #333; min-height: 80px; position: relative; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 5px; margin-top: 15px; }
        .signature-img { position: absolute; max-height: 70px; max-width: 150px; bottom: 5px; object-fit: contain; }
        .signer-name { font-weight: 800; font-size: 0.85rem; text-transform: uppercase; margin-top: 5px; color: #111; }
        .signer-role { font-size: 0.75rem; color: #666; text-transform: capitalize; }
        .signature-block { page-break-inside: avoid; break-inside: avoid; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 8rem; opacity: 0.05; font-weight: 900; pointer-events: none; color: #003087; }
    </style>
</head>
<body>

<div class="container no-print py-4 text-center">
    <button onclick="window.print()" class="btn btn-primary px-4 fw-bold shadow-sm">
        <i class="bi bi-printer me-2"></i>Print Permission Form
    </button>
    <button onclick="goBack()" class="btn btn-outline-secondary px-4 ms-2">
        <i class="bi bi-arrow-left me-2"></i>Go Back
    </button>
</div>

<div class="permission-form shadow">
    <div class="watermark">{{ strtoupper($res->status) }}</div>

    <!-- Header -->
    <div class="nu-header d-flex align-items-center gap-4">
        <img src="{{ asset('assets/img/NU_shield.png') }}" alt="NU Logo" style="width: 85px; height: 85px; object-fit: contain;">
        <div>
            <h2 class="mb-1" style="color:#003087; font-weight: 900; font-family: 'Arial Black', sans-serif;">NATIONAL UNIVERSITY</h2>
            <h5 class="mb-0 text-muted fw-bold">CLARK CAMPUS</h5>
        </div>
        <div class="ms-auto text-end">
            <h4 class="form-title mb-0">Venue Reservation<br>Permission Form</h4>
        </div>
    </div>

    <!-- Details Section -->
    <div class="mb-5">
        <h6 class="bg-light p-2 fw-bold text-uppercase mb-3" style="border-left: 5px solid #003087;">Event Information</h6>
        <div class="mb-3">
            <span class="info-label text-uppercase">Event Title:</span>
            <span class="info-value fw-bold">{{ $res->event?->title ?? $res->event_title }}</span>
        </div>
        <div class="row mb-3">
            <div class="col-6">
                <span class="info-label text-uppercase">Venue Request:</span>
                <span class="info-value">
                    @if($res->rooms->isNotEmpty())
                        {{ implode(', ', $res->rooms->pluck('room_name')->toArray()) }}
                    @else
                        {{ $res->venue_name }}
                    @endif
                </span>
            </div>
            <div class="col-6">
                <span class="info-label text-uppercase">Date:</span>
                <span class="info-value">{{ $res->reserved_date->format('F d, Y') }}</span>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-6">
                <span class="info-label text-uppercase">Time:</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($res->start_time)->format('h:i A') }} – {{ \Carbon\Carbon::parse($res->end_time)->format('h:i A') }}</span>
            </div>
            <div class="col-6">
                <span class="info-label text-uppercase">Attendees:</span>
                <span class="info-value">{{ $res->expected_attendees ?? 'N/A' }} Pax</span>
            </div>
        </div>
        <div class="mb-3">
            <span class="info-label text-uppercase">Purpose:</span>
            <span class="info-value">{{ $res->purpose ?? 'University Event / Student Activity' }}</span>
        </div>
    </div>

    <div class="mb-5">
        <h6 class="bg-light p-2 fw-bold text-uppercase mb-3" style="border-left: 5px solid #003087;">Equipment Request & Accountability Checklist</h6>
        <div class="mb-3"><span class="info-label text-uppercase">Requested By:</span><span class="info-value">{{ $res->reservedBy->full_name }}</span></div>
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light"><tr><th style="width:38px">Check</th><th>Equipment / Item</th><th style="width:80px">Quantity</th><th>Purpose / Setup Note</th><th style="width:100px">Status</th></tr></thead>
            <tbody>
            @forelse($res->equipmentRequests as $item)
                <tr><td class="text-center">☐</td><td>{{ $item->item_name }}</td><td class="text-center">{{ $item->quantity }}</td><td>{{ $item->purpose ?: '—' }}</td><td>{{ ucfirst($item->status) }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">No equipment requested.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="small text-muted mb-0">Signatories should verify availability and note any unavailable item before approval. The requester remains accountable for issued items.</p>
    </div>

    <div class="mb-5">
        <h6 class="bg-light p-2 fw-bold text-uppercase mb-3" style="border-left: 5px solid #003087;">Department Details</h6>
        <div class="mb-3">
            <span class="info-label text-uppercase">Reserved By:</span>
            <span class="info-value">{{ $res->reservedBy->full_name }}</span>
        </div>
        <div class="mb-3">
            <span class="info-label text-uppercase">Submission Date:</span>
            <span class="info-value">{{ $res->created_at->format('F d, Y h:i A') }}</span>
        </div>
    </div>

    <!-- Signatures Section -->
    <div class="mt-5 pt-4">
        <h6 class="bg-light p-2 fw-bold text-uppercase mb-3" style="border-left: 5px solid #003087;">Approval Chain & E-Signatures</h6>
        <p class="text-muted small mb-5 fst-italic">This document serves as an official permission form. Any alterations to this printed form without system validation are invalid.</p>
        
        <div class="row g-5 justify-content-center">

            <!-- Dynamic Approval Chain -->
            @php
                $activeSignatoris = \App\Models\FileHuntingSignatory::where('is_active', 1)->orderBy('step_order')->get();
            @endphp

            @foreach($activeSignatoris as $sig)
                @php 
                    $approval = $res->approvals->where('role_level', $sig->role)->where('status', 'approved')->first(); 
                    $roleUser = \App\Models\User::where('role', $sig->role)->first();
                    $signerName = $approval ? $approval->approver->full_name : ($roleUser ? $roleUser->full_name : 'N/A');
                @endphp
                <div class="col-4 text-center mb-5 signature-block">
                    <div class="signature-box">
                        @if($approval && $approval->e_signature_used)
                            <img src="{{ \App\Helpers\StorageUrl::url($approval->approver->e_signature_path ?? $approval->e_signature_used) }}" class="signature-img" alt="Signature">
                        @else
                            <div class="fw-bold small fst-italic text-muted">Awaiting Action</div>
                        @endif
                    </div>
                    <div class="signer-name text-uppercase">{{ $signerName }}</div>
                    <div class="signer-role">{{ $sig->position_label }}</div>
                </div>
            @endforeach
            
        </div>
    </div>
</div>

<script>
    // Go back function with fallback
    function goBack() {
        if (window.history.length > 1 && document.referrer !== '') {
            window.history.back();
        } else {
            @if(Auth::check() && Auth::user()->role === 'student_department')
                window.location.href = '{{ route("student_department.dashboard") }}';
            @elseif(Auth::check() && in_array(Auth::user()->role, ['adviser', 'department_head', 'dean', 'executive_director', 'program_chair']))
                window.location.href = '{{ route("approver.dashboard") }}';
            @else
                window.location.href = '{{ url("/") }}';
            @endif
        }
    }

    // Auto-trigger print dialog if requested
    if (window.location.search.includes('print=1')) {
        window.print();
    }
</script>

</body>
</html>
