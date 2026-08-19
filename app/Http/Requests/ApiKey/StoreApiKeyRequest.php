<?php

namespace App\Http\Requests\ApiKey;

use App\Domain\ApiKey\ApiKeyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreApiKeyRequest extends FormRequest
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
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [new Enum(ApiKeyScope::class)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
