<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Registration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function register(int $userId, int $eventId): Registration
    {
        return DB::transaction(function () use ($userId, $eventId) {
            // Serialize seat allocation for both web and API requests.
            $event = Event::published()->lockForUpdate()->findOrFail($eventId);
            $endsAt = Carbon::parse($event->event_date->toDateString().' '.$event->end_time);

            if ($endsAt->isPast()) {
                throw ValidationException::withMessages(['event' => 'This event has already passed.']);
            }

            $registration = Registration::where('user_id', $userId)
                ->where('event_id', $eventId)->lockForUpdate()->first();

            if ($registration && $registration->status !== 'cancelled') {
                throw ValidationException::withMessages(['event' => 'You are already registered for this event.']);
            }
            if ($registration?->attendance()->exists()) {
                throw ValidationException::withMessages(['event' => 'This registration already has an attendance record. Please contact the event organizer.']);
            }
            if ($event->isFull()) {
                throw ValidationException::withMessages(['event' => 'This event is already at full capacity.']);
            }

            $registration ??= new Registration(['user_id' => $userId, 'event_id' => $eventId]);
            $registration->fill([
                'qr_token' => Registration::generateQrToken($userId, $eventId),
                'qr_expires_at' => $endsAt->addDay(),
                'status' => 'confirmed',
                'registered_at' => now(),
            ])->save();

            return $registration;
        });
    }
}
