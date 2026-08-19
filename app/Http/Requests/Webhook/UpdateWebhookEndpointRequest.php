<?php

namespace App\Http\Requests\Webhook;

use App\Domain\Webhook\WebhookEndpointStatus;
use App\Domain\Webhook\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('webhookEndpoint'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'url', 'starts_with:https://'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => [new Enum(WebhookEventType::class)],
            'status' => ['sometimes', new Enum(WebhookEndpointStatus::class)],
        ];
    }
}
