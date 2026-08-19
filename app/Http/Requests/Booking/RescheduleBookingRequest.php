<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reschedule', $this->route('booking'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date', 'after_or_equal:now'],
        ];
    }
}
