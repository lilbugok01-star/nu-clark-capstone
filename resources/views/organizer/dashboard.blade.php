@extends('layouts.app')
@section('title', 'Organizer Dashboard')
@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold mb-0" style="color:var(--nu-blue)">Organizer Dashboard</h4>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-nu-blue fw-bold" data-bs-toggle="modal" data-bs-target="#webScannerModal">
                        <i class="bi bi-qr-code-scan me-1"></i>Web Scanner
                    </button>
                    <a href="{{ route('organizer.event.create') }}" class="btn btn-gold">
                        <i class="bi bi-plus me-1"></i>Create
                    </a>
                </div>
            </div>

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="stat-card"><div class="stat-value">{{ $stats['total_events'] }}</div><div class="stat-label">My Events</div></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card" style="border-color:var(--nu-gold)"><div class="stat-value text-gold">{{ $stats['upcoming_events'] }}</div><div class="stat-label">Upcoming</div></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card" style="border-color:#28a745"><div class="stat-value" style="color:#28a745">{{ $stats['total_regs'] }}</div><div class="stat-label">Total Registrations</div></div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-card" style="border-color:#6f42c1"><div class="stat-value" style="color:#6f42c1">{{ $stats['verified_att'] }}</div><div class="stat-label">Verified Attendances</div></div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="nu-card p-4">
                        <h6 class="fw-bold mb-3">My Recent Events</h6>
                        @forelse($myEvents as $e)
                        <div class="d-flex align-items-center gap-3 mb-3 p-2 rounded-xl event-list-card" style="background:var(--white); border: 1px solid var(--gray-200); box-shadow: var(--shadow-sm); transition: all 0.3s ease;">
                            <div class="text-center bg-light rounded p-2" style="min-width:55px">
                                <div class="fw-bold lh-1" style="color:var(--nu-blue);font-size:1.4rem">{{ $e->event_date->format('d') }}</div>
                                <div class="text-muted small fw-600 mt-1" style="font-size:0.75rem; text-transform:uppercase;">{{ $e->event_date->format('M') }}</div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold" style="color:var(--nu-blue);">{{ $e->title }}</div>
                                <div class="text-muted" style="font-size:0.75rem"><i class="bi bi-people me-1"></i>{{ $e->reg_count }} registered</div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="{{ route('organizer.event.attendees', $e->id) }}" class="btn btn-outline-gold btn-sm" title="Attendees"><i class="bi bi-people"></i></a>
                                <a href="{{ route('organizer.event.edit', $e->id) }}" class="btn btn-outline-secondary btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
                            </div>
                        </div>
                        @empty
                        <p class="text-muted small text-center mt-3">No events yet. <a href="{{ route('organizer.event.create') }}">Create one →</a></p>
                        @endforelse
                    </div>

                    @push('styles')
                        <link href="{{ asset('css/organizer/dashboard.css') }}" rel="stylesheet">
                    @endpush
                </div>

                <!-- Pending Verifications -->
                <div class="col-lg-5">
                    <div class="nu-card p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-camera text-gold me-2"></i>Pending Verifications</h6>
                        <div class="pending-verification-list" style="max-height: 400px; overflow-y: auto; padding-right: 5px;">
                            @forelse($pendingVerifications as $att)
                            <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background:var(--gray-100)">
                                @if($att->photo_path || $att->checkout_photo_path)
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#photoModal{{ $att->id }}" title="View Attendance Selfie(s)">
                                        <img src="{{ \App\Helpers\StorageUrl::url($att->photo_path ?: $att->checkout_photo_path) }}" class="attendance-photo" style="cursor:pointer;object-fit:cover;width:42px;height:42px;border-radius:50%;border:2px solid var(--nu-blue)" alt="photo">
                                    </a>

                                    <!-- Photo Modal -->
                                    <div class="modal fade" id="photoModal{{ $att->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered {{ ($att->photo_path && $att->checkout_photo_path) ? 'modal-lg' : '' }}">
                                            <div class="modal-content rounded-3 overflow-hidden">
                                                <div class="modal-header border-0 pb-0">
                                                    <strong class="small" style="color:var(--nu-blue)">{{ $att->registration->user->full_name }} — Attendance Verification</strong>
                                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-3">
                                                    <div class="row g-2">
                                                        @if($att->photo_path)
                                                            <div class="{{ $att->checkout_photo_path ? 'col-md-6' : 'col-12' }} text-center">
                                                                <div class="badge bg-success-subtle text-success mb-2 fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i>Time In: {{ $att->checked_in_at?->format('h:i A') ?? 'Recorded' }}</div>
                                                                <img src="{{ \App\Helpers\StorageUrl::url($att->photo_path) }}" class="w-100 rounded-2 bg-dark shadow-sm" style="object-fit:contain;max-height:380px" alt="Time In Photo">
                                                            </div>
                                                        @endif
                                                        @if($att->checkout_photo_path)
                                                            <div class="{{ $att->photo_path ? 'col-md-6' : 'col-12' }} text-center">
                                                                <div class="badge bg-primary-subtle text-primary mb-2 fw-semibold"><i class="bi bi-box-arrow-right me-1"></i>Time Out: {{ $att->checked_out_at?->format('h:i A') ?? 'Recorded' }}</div>
                                                                <img src="{{ \App\Helpers\StorageUrl::url($att->checkout_photo_path) }}" class="w-100 rounded-2 bg-dark shadow-sm" style="object-fit:contain;max-height:380px" alt="Time Out Photo">
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="modal-footer d-flex gap-2 border-0 pt-0">
                                                    <form action="{{ route('organizer.attendance.verify', $att->id) }}" method="POST" class="d-flex gap-2 w-100" style="margin:0">
                                                        @csrf @method('PUT')
                                                        <button name="status" value="verified" class="btn btn-success flex-grow-1 fw-700" onclick="document.querySelector('#photoModal{{ $att->id }} .btn-close').click()"><i class="bi bi-check-lg me-1"></i>Verify</button>
                                                        <button name="status" value="rejected" class="btn btn-danger flex-grow-1 fw-700" onclick="document.querySelector('#photoModal{{ $att->id }} .btn-close').click()"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <div class="attendance-photo d-flex align-items-center justify-content-center" style="background:#eee;width:42px;height:42px;border-radius:50%"><i class="bi bi-person text-muted"></i></div>
                                @endif
                                <div class="flex-grow-1">
                                    <div class="small fw-semibold">{{ $att->registration->user->full_name }}</div>
                                    <div class="text-muted" style="font-size:0.72rem">{{ $att->registration->event->title }}</div>
                                </div>
                                <form action="{{ route('organizer.attendance.verify', $att->id) }}" method="POST" class="d-flex gap-1" style="z-index:10;">
                                    @csrf @method('PUT')
                                    <button name="status" value="verified" class="btn btn-success btn-sm py-0 px-2" title="Verify"><i class="bi bi-check"></i></button>
                                    <button name="status" value="rejected" class="btn btn-danger btn-sm py-0 px-2" title="Reject"><i class="bi bi-x"></i></button>
                                </form>
                            </div>
                            @empty
                            <p class="text-muted small text-center mt-3">No pending verifications. 🎉</p>
                            @endforelse
                        </div>
                        <a href="{{ route('organizer.events') }}" class="btn btn-outline-gold btn-sm w-100 mt-2">View All Events</a>
                    </div>
                </div>
            </div>

            <!-- Events Calendar -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="nu-card p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-calendar3 text-gold me-2"></i>Events Calendar</h6>
                        <x-event-calendar calendarId="organizerCalendar" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Web Scanner Modal -->
<div class="modal fade" id="webScannerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-3 overflow-hidden">
            <div class="modal-header border-0 pb-0">
                <strong class="small" style="color:var(--nu-blue)"><i class="bi bi-camera me-2"></i>Webcam QR Scanner</strong>
                <button class="btn-close" data-bs-dismiss="modal" id="closeScannerModal"></button>
            </div>
            <div class="modal-body text-center p-3">
                <!-- Idle overlay shown before camera starts -->
                <div id="scannerIdle" class="d-flex flex-column align-items-center justify-content-center rounded-3"
                     style="height:300px; background:linear-gradient(135deg,#f0f4ff,#e8eeff);">
                    <div style="background:rgba(0,51,160,0.08);border-radius:50%;width:80px;height:80px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                        <i class="bi bi-qr-code-scan" style="font-size:2.2rem;color:var(--nu-blue);"></i>
                    </div>
                    <p class="fw-semibold mb-1" style="color:var(--nu-blue);font-size:0.95rem;">Ready to Scan</p>
                    <p class="text-muted small mb-3" style="font-size:0.78rem;">Click below to activate your webcam</p>
                    <button id="startCameraBtn" type="button"
                            class="btn fw-bold px-4 py-2 rounded-pill shadow-sm"
                            style="background:var(--nu-blue);color:#fff;font-size:0.9rem;">
                        <i class="bi bi-play-circle-fill me-2"></i>Start Camera
                    </button>
                    <div id="scannerError" class="mt-3 small text-danger d-none"></div>
                </div>

                <!-- Live camera feed (hidden until started) -->
                <div id="scannerLive" class="d-none rounded-3 overflow-hidden" style="position:relative;background:#000;">
                    <video id="scannerVideo" playsinline autoplay muted
                           style="width:100%;display:block;max-height:320px;object-fit:cover;"></video>
                    <!-- Scanning frame overlay -->
                    <div style="position:absolute;inset:0;pointer-events:none;display:flex;align-items:center;justify-content:center;">
                        <div style="width:180px;height:180px;border:3px solid var(--nu-gold);border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,0.35);"></div>
                    </div>
                    <div style="position:absolute;bottom:10px;left:0;right:0;text-align:center;">
                        <span class="badge" style="background:rgba(0,0,0,0.55);color:#fff;font-size:0.75rem;padding:4px 12px;border-radius:20px;">
                            <span class="spinner-grow spinner-grow-sm me-1" style="color:var(--nu-gold);"></span>Scanning…
                        </span>
                    </div>
                </div>

                <!-- Hidden canvas used for jsQR processing -->
                <canvas id="scannerCanvas" style="display:none;"></canvas>

                <p class="text-muted small mt-3 mb-0">Point the student's QR code at the gold frame to check them in.</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"
    integrity="sha384-b5Ya4Bq3qCyz39m2ISh+4DxjAIljdeFwK/BsXLuj9gugaNwAcj/ia15fxNZL9Nlx" crossorigin="anonymous"></script>
<script>
    let cameraStream   = null;
    let scanInterval   = null;
    const scannerModal = document.getElementById('webScannerModal');

    function stopCamera() {
        if (scanInterval)  { clearInterval(scanInterval);  scanInterval  = null; }
        if (cameraStream)  { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
        // Reset UI back to idle
        document.getElementById('scannerLive').classList.add('d-none');
        document.getElementById('scannerIdle').classList.remove('d-none');
        const btn = document.getElementById('startCameraBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle-fill me-2"></i>Start Camera';
        document.getElementById('scannerError').classList.add('d-none');
    }

    // Stop camera whenever modal closes
    scannerModal.addEventListener('hidden.bs.modal', stopCamera);

    document.getElementById('startCameraBtn').addEventListener('click', async function () {
        const btn = document.getElementById('startCameraBtn');
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Requesting Access...';
        btn.disabled = true;

        try {
            // Request camera — prefer rear/environment on mobile, fallback to any
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
            });

            const video  = document.getElementById('scannerVideo');
            const canvas = document.getElementById('scannerCanvas');
            const ctx    = canvas.getContext('2d');

            video.srcObject = cameraStream;
            await video.play();

            // Switch UI: hide idle, show live feed
            document.getElementById('scannerIdle').classList.add('d-none');
            document.getElementById('scannerLive').classList.remove('d-none');

            // Scan a frame every 100ms with jsQR
            scanInterval = setInterval(function () {
                if (video.readyState !== video.HAVE_ENOUGH_DATA) return;
                canvas.width  = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(imageData.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
                if (code && code.data) {
                    let scanUrl;
                    try {
                        scanUrl = new URL(code.data);
                        if (scanUrl.origin !== window.location.origin || !/^\/organizer\/scan\/[^/]+$/.test(scanUrl.pathname)) return;
                    } catch (_) {
                        return;
                    }
                    stopCamera();
                    const modalInstance = bootstrap.Modal.getInstance(scannerModal);
                    if (modalInstance) modalInstance.hide();
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = scanUrl.origin + scanUrl.pathname;
                    for (const [name, value] of Object.entries({ _token: @json(csrf_token()), scan_id: crypto.randomUUID() })) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    }
                    document.body.appendChild(form);
                    form.submit();
                }
            }, 100);

        } catch (err) {
            // Show error inside the idle panel so the user can retry
            const errEl = document.getElementById('scannerError');
            errEl.textContent = err.name === 'NotAllowedError'
                ? 'Camera permission denied. Allow camera access in your browser settings and try again.'
                : 'Could not start camera: ' + err.message;
            errEl.classList.remove('d-none');
            btn.innerHTML = '<i class="bi bi-play-circle-fill me-2"></i>Retry';
            btn.disabled = false;
        }
    });
</script>
@endpush

@endsection
