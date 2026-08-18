<?php

namespace App\Http\Requests\Service;

use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'resource_ids' => ['sometimes', 'array'],
            'resource_ids.*' => ['string', 'exists:resources,public_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $organization = Organization::where('public_id', $this->input('organization_id'))->first();

            if (! $organization || ! $this->filled('resource_ids')) {
                return;
            }

            $foreignResourceCount = Resource::whereIn('public_id', $this->input('resource_ids'))
                ->where('organization_id', '!=', $organization->id)
                ->count();

            if ($foreignResourceCount > 0) {
                $validator->errors()->add('resource_ids', 'All resources must belong to the given organization.');
            }
        });
    }
}
