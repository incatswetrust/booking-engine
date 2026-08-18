<?php

namespace App\Http\Requests\ResourceGroup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResourceGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resourceGroup'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
