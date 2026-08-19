<?php

namespace App\Http\Requests\Booking;

use App\Domain\Booking\BookingHold;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
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
            'resource_id' => ['required', 'string', 'exists:resources,public_id'],
            'service_id' => ['required', 'string', 'exists:services,public_id'],
            'start_at' => ['required', 'date', 'after_or_equal:now'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'customer_id' => ['sometimes', 'string', 'exists:users,public_id'],
            'hold_id' => ['sometimes', 'string', 'exists:booking_holds,public_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $resource = Resource::where('public_id', $this->input('resource_id'))->first();
            $service = Service::where('public_id', $this->input('service_id'))->first();

            if (! $resource || ! $service) {
                return;
            }

            if ($resource->organization_id !== $service->organization_id) {
                $validator->errors()->add('service_id', 'The service does not belong to the same organization as the resource.');

                return;
            }

            if (! $service->resources()->where('resources.id', $resource->id)->exists()) {
                $validator->errors()->add('service_id', 'This service is not offered on the given resource.');
            }

            if ($this->filled('hold_id')) {
                $hold = BookingHold::where('public_id', $this->input('hold_id'))->first();

                if ($hold && ($hold->resource_id !== $resource->id || $hold->service_id !== $service->id)) {
                    $validator->errors()->add('hold_id', 'The hold does not match the given resource and service.');
                }

                if ($hold && $hold->isExpired()) {
                    $validator->errors()->add('hold_id', 'This hold has expired.');
                }
            }
        });
    }
}
