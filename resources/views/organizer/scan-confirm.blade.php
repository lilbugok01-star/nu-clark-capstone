@extends('layouts.app')
@section('title', 'Confirm Attendance')

@section('content')
<div class="container py-4" style="max-width: 560px">
    <h1 class="h4">{{ $registration->event->title }}</h1>
    <p class="mb-1">{{ $registration->user->full_name }}</p>
    <p class="text-muted">{{ $registration->user->student_id }}</p>
    @if($registration->attendance?->checked_out_at)
        <p>Attendance already completed.</p>
    @else
        <form method="POST" action="{{ route('organizer.scan.record', $token) }}">
            @csrf
            <input type="hidden" name="scan_id" value="{{ \Illuminate\Support\Str::uuid() }}">
            <button type="submit" class="btn btn-nu-blue">
                <i class="bi bi-check2 me-1"></i>
                {{ $registration->attendance ? 'Record Time Out' : 'Record Time In' }}
            </button>
        </form>
    @endif
    <a href="{{ route('organizer.dashboard') }}" class="btn btn-link mt-3">Back to Dashboard</a>
</div>
@endsection
