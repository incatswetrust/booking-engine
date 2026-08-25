<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthRequest extends FormRequest
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
            'code' => ['required', 'string'],
            // Must exactly match the redirect_uri the dashboard used to
            // build the authorization URL -- Google's token endpoint
            // rejects the exchange otherwise, so there's nothing extra to
            // allowlist here (§49's allowlist concern is about the
            // Calendar OAuth return_to, a different, still-open item).
            'redirect_uri' => ['required', 'url'],
            'code_verifier' => ['required', 'string'],
        ];
    }
}
