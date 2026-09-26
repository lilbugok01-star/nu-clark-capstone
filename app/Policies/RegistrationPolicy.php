<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;

class RegistrationPolicy
{
    public function view(User $user, Registration $registration): bool
    {
        return $user->isAdmin()
            || (int) $registration->user_id === (int) $user->id
            || (in_array($user->role, ['organizer', 'student_development'])
                && (int) $registration->event?->organizer_id === (int) $user->id);
    }
}
