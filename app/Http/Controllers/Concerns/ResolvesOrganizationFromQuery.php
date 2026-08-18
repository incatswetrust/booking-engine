<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Organization\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesOrganizationFromQuery
{
    protected function resolveOrganization(Request $request): Organization
    {
        $publicId = $request->query('organization_id');

        if (! is_string($publicId) || $publicId === '') {
            throw ValidationException::withMessages([
                'organization_id' => ['The organization_id query parameter is required.'],
            ]);
        }

        return Organization::where('public_id', $publicId)->firstOrFail();
    }
}
