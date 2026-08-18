<?php

namespace App\Http\Requests\Resource;

use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreResourceRequest extends FormRequest
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
            'organization_id' => ['required', 'string', 'exists:organizations,public_id'],
            'location_id' => ['required', 'string', 'exists:locations,public_id'],
            'resource_group_id' => ['nullable', 'string', 'exists:resource_groups,public_id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $organization = Organization::where('public_id', $this->input('organization_id'))->first();

            if (! $organization) {
                return;
            }

            if ($this->filled('location_id')) {
                $location = Location::where('public_id', $this->input('location_id'))->first();

                if ($location && $location->organization_id !== $organization->id) {
                    $validator->errors()->add('location_id', 'The location does not belong to the given organization.');
                }
            }

            if ($this->filled('resource_group_id')) {
                $group = ResourceGroup::where('public_id', $this->input('resource_group_id'))->first();

                if ($group && $group->organization_id !== $organization->id) {
                    $validator->errors()->add('resource_group_id', 'The resource group does not belong to the given organization.');
                }
            }
        });
    }
}
