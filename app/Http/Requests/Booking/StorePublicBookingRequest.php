<?php

namespace App\Http\Requests\Booking;

use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * §69: the unauthenticated counterpart to StoreBookingRequest -- no
 * customer_id override (a public visitor can only ever book as
 * themselves, identified by customer_email) and no hold_id (holds
 * require a Sanctum session today, so a public flow always books
 * directly rather than holding first).
 */
class StorePublicBookingRequest extends FormRequest
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
            'resource_id' => ['sometimes', 'string', 'exists:resources,public_id'],
            'location_id' => ['sometimes', 'string', 'exists:locations,public_id'],
            'service_id' => ['required', 'string', 'exists:services,public_id'],
            'start_at' => ['required', 'date', 'after_or_equal:now'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $service = Service::where('public_id', $this->input('service_id'))->first();

            if (! $service) {
                return;
            }

            if (! $this->filled('resource_id')) {
                return;
            }

            $resource = Resource::where('public_id', $this->input('resource_id'))->first();

            if (! $resource) {
                return;
            }

            if ($resource->organization_id !== $service->organization_id) {
                $validator->errors()->add('service_id', 'The service does not belong to the same organization as the resource.');

                return;
            }

            if (! $service->resources()->where('resources.id', $resource->id)->exists()) {
                $validator->errors()->add('service_id', 'This service is not offered on the given resource.');
            }

            if ($this->filled('party_size') && (int) $this->input('party_size') > $resource->capacity) {
                $validator->errors()->add('party_size', "This resource's capacity is {$resource->capacity}.");
            }
        });
    }
}
