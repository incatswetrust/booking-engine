<?php

namespace App\Http\Requests\BookingHold;

use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingHoldRequest extends FormRequest
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
        });
    }
}
