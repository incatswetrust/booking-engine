<?php

namespace App\Http\Requests\Service;

use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'max:255'],
            'resource_ids' => ['sometimes', 'array'],
            'resource_ids.*' => ['string', 'exists:resources,public_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Service $service */
            $service = $this->route('service');

            if (! $this->filled('resource_ids')) {
                return;
            }

            $foreignResourceCount = Resource::whereIn('public_id', $this->input('resource_ids'))
                ->where('organization_id', '!=', $service->organization_id)
                ->count();

            if ($foreignResourceCount > 0) {
                $validator->errors()->add('resource_ids', 'All resources must belong to this service\'s organization.');
            }
        });
    }
}
