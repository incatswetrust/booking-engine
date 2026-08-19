<?php

namespace App\Http\Requests\Availability;

use App\Domain\Location\Location;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
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
            'location_id' => ['sometimes', 'string', 'exists:locations,public_id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', 'timezone'],
            'party_size' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $service = Service::where('public_id', $this->input('service_id'))->first();

            if (! $service) {
                return;
            }

            if ($this->filled('resource_id')) {
                $resource = Resource::where('public_id', $this->input('resource_id'))->first();

                if ($resource && ! $service->resources()->where('resources.id', $resource->id)->exists()) {
                    $validator->errors()->add('resource_id', 'This service is not offered on the given resource.');
                }
            }

            if ($this->filled('location_id')) {
                $location = Location::where('public_id', $this->input('location_id'))->first();

                if ($location && $location->organization_id !== $service->organization_id) {
                    $validator->errors()->add('location_id', 'The location does not belong to the same organization as the service.');
                }
            }
        });
    }
}
