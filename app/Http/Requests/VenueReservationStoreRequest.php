<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VenueReservationStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'event_id'           => 'required|string',
            'event_title'        => 'nullable|string',
            'rooms'              => 'required|array|min:1',
            'rooms.*'            => 'required|string',
            'reserved_date'      => 'required|date|after_or_equal:today',
            'start_time'         => 'required|date_format:H:i:s,H:i|after_or_equal:08:00',
            'end_time'           => [
                'required',
                'date_format:H:i:s,H:i',
                'after:start_time',
                'before_or_equal:22:00',
                function ($attribute, $value, $fail) {
                    if ($this->filled('start_time') && $value) {
                        $start = \Carbon\Carbon::parse($this->input('start_time'));
                        $end   = \Carbon\Carbon::parse($value);
                        if ($start->diffInMinutes($end, false) < 60) {
                            $fail('The reservation duration must be at least 1 hour (60 minutes).');
                        }
                    }
                },
            ],
            'expected_attendees' => 'nullable|integer|min:1',
            'purpose'            => 'nullable|string',
            'equipment_items'    => 'nullable|array|max:20',
            'equipment_items.*.item_name' => 'nullable|string|max:150',
            'equipment_items.*.quantity'  => 'nullable|integer|min:1|max:10000',
            'equipment_items.*.purpose'   => 'nullable|string|max:255',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reserved_date.after_or_equal' => 'The reservation date must be today or a future date.',
            'start_time.after_or_equal'    => 'Reservation start time must be 08:00 AM or later.',
            'end_time.before_or_equal'     => 'Reservation end time must be 10:00 PM or earlier.',
            'rooms.required'               => 'You must select at least one room.',
        ];
    }
}
