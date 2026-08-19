<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'string', 'exists:services,public_id'],
            'resource_id' => ['sometimes', 'string', 'exists:resources,public_id'],
            'desired_start_at' => ['required', 'date', 'after:now'],
        ];
    }
}
