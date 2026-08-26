<?php

namespace App\Http\Requests\Service;

use App\Domain\Resource\Resource;
use App\Domain\Service\PaymentMode;
use App\Domain\Service\Service;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
            'pricing_rules' => ['sometimes', 'nullable', 'array'],
            'pricing_rules.weekend_price' => ['sometimes', 'numeric', 'min:0'],
            'pricing_rules.time_of_day_multipliers' => ['sometimes', 'array'],
            'pricing_rules.time_of_day_multipliers.*.start' => ['required', 'date_format:H:i'],
            'pricing_rules.time_of_day_multipliers.*.end' => ['required', 'date_format:H:i'],
            'pricing_rules.time_of_day_multipliers.*.multiplier' => ['required', 'numeric', 'min:0'],
            'pricing_rules.occupancy_surcharge' => ['sometimes', 'array'],
            'pricing_rules.occupancy_surcharge.threshold_percent' => ['required_with:pricing_rules.occupancy_surcharge', 'numeric', 'min:0', 'max:100'],
            'pricing_rules.occupancy_surcharge.multiplier' => ['required_with:pricing_rules.occupancy_surcharge', 'numeric', 'min:0'],
            'cancellation_policy' => ['sometimes', 'nullable', 'array'],
            'cancellation_policy.notice_minutes' => ['sometimes', 'integer', 'min:0'],
            'cancellation_policy.refund_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', 'string', 'max:255'],
            'resource_ids' => ['sometimes', 'array'],
            'resource_ids.*' => ['string', 'exists:resources,public_id'],
            'payment_mode' => ['sometimes', new Enum(PaymentMode::class)],
            'deposit_amount' => ['required_if:payment_mode,deposit', 'nullable', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Service $service */
            $service = $this->route('service');

            if ($this->filled('resource_ids')) {
                $foreignResourceCount = Resource::whereIn('public_id', $this->input('resource_ids'))
                    ->where('organization_id', '!=', $service->organization_id)
                    ->count();

                if ($foreignResourceCount > 0) {
                    $validator->errors()->add('resource_ids', 'All resources must belong to this service\'s organization.');
                }
            }

            $effectivePaymentMode = $this->input('payment_mode', $service->payment_mode?->value);
            $effectivePrice = $this->input('price', (string) $service->price);

            if ($effectivePaymentMode === 'deposit'
                && $this->filled('deposit_amount')
                && (float) $this->input('deposit_amount') > (float) $effectivePrice) {
                $validator->errors()->add('deposit_amount', 'The deposit amount cannot exceed the service price.');
            }

            if ($effectivePaymentMode !== PaymentMode::None->value) {
                $stripeAccount = $service->organization->stripeAccount;

                if ($stripeAccount === null || ! $stripeAccount->charges_enabled) {
                    $validator->errors()->add(
                        'payment_mode',
                        'Connect a Stripe account for this organization before enabling paid bookings for this service.',
                    );
                }
            }
        });
    }
}
