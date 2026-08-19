<?php

namespace App\Http\Requests\Resource;

use App\Domain\Location\Location;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resource'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'string', 'exists:locations,public_id'],
            'resource_group_id' => ['nullable', 'string', 'exists:resource_groups,public_id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var resource $resource */
            $resource = $this->route('resource');

            if ($this->filled('location_id')) {
                $location = Location::where('public_id', $this->input('location_id'))->first();

                if ($location && $location->organization_id !== $resource->organization_id) {
                    $validator->errors()->add('location_id', 'The location does not belong to this resource\'s organization.');
                }
            }

            if ($this->filled('resource_group_id')) {
                $group = ResourceGroup::where('public_id', $this->input('resource_group_id'))->first();

                if ($group && $group->organization_id !== $resource->organization_id) {
                    $validator->errors()->add('resource_group_id', 'The resource group does not belong to this resource\'s organization.');
                }
            }
        });
    }
}
