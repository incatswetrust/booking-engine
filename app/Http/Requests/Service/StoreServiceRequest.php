<?php

namespace App\Http\Requests\Service;

use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\PaymentMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
            'resource_ids' => ['sometimes', 'array'],
            'resource_ids.*' => ['string', 'exists:resources,public_id'],
            'payment_mode' => ['sometimes', new Enum(PaymentMode::class)],
            'deposit_amount' => ['required_if:payment_mode,deposit', 'nullable', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $organization = Organization::where('public_id', $this->input('organization_id'))->first();

            if ($organization && $this->filled('resource_ids')) {
                $foreignResourceCount = Resource::whereIn('public_id', $this->input('resource_ids'))
                    ->where('organization_id', '!=', $organization->id)
                    ->count();

                if ($foreignResourceCount > 0) {
                    $validator->errors()->add('resource_ids', 'All resources must belong to the given organization.');
                }
            }

            if ($this->input('payment_mode') === 'deposit'
                && $this->filled('deposit_amount')
                && $this->filled('price')
                && (float) $this->input('deposit_amount') > (float) $this->input('price')) {
                $validator->errors()->add('deposit_amount', 'The deposit amount cannot exceed the service price.');
            }

            $paymentMode = $this->input('payment_mode', PaymentMode::None->value);

            if ($organization && $paymentMode !== PaymentMode::None->value) {
                $stripeAccount = $organization->stripeAccount;

                if ($stripeAccount === null || ! $stripeAccount->charges_enabled) {
                    $validator->errors()->add(
                        'payment_mode',
                        'Connect a Stripe account for this organization before creating a paid service.',
                    );
                }
            }
        });
    }
}
