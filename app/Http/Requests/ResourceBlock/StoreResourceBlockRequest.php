<?php

namespace App\Http\Requests\ResourceBlock;

use App\Domain\Resource\ResourceBlockReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreResourceBlockRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', new Enum(ResourceBlockReason::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
