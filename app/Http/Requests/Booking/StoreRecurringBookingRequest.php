<?php

namespace App\Http\Requests\Booking;

use App\Domain\Booking\RecurringBookingStrategy;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreRecurringBookingRequest extends FormRequest
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
            'first_start_at' => ['required', 'date', 'after_or_equal:now'],
            // §72's example is "8 weeks"; 52 is a generous cap (a year of
            // weekly occurrences) against accidental/abusive huge series.
            'occurrences' => ['required', 'integer', 'min:1', 'max:52'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'strategy' => ['required', new Enum(RecurringBookingStrategy::class)],
            'customer_id' => ['sometimes', 'string', 'exists:users,public_id'],
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

            if ($this->filled('party_size') && (int) $this->input('party_size') > $resource->capacity) {
                $validator->errors()->add('party_size', "This resource's capacity is {$resource->capacity}.");
            }
        });
    }
}
