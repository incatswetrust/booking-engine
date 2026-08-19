<?php

namespace App\Http\Requests\ResourceGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceGroupRequest extends FormRequest
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
        ];
    }
}
