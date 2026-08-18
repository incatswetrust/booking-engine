<?php

namespace App\Http\Requests\Schedule;

use App\Domain\Resource\Resource;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var resource $resource */
        $resource = $this->route('resource');

        return $this->user()->can('update', $resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array'],
            'rules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i'],
            'rules.*.valid_from' => ['nullable', 'date'],
            'rules.*.valid_until' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('rules', []) as $index => $rule) {
                if (isset($rule['start_time'], $rule['end_time']) && $rule['start_time'] >= $rule['end_time']) {
                    $validator->errors()->add("rules.{$index}.end_time", 'The end time must be after the start time.');
                }

                if (isset($rule['valid_from'], $rule['valid_until']) && $rule['valid_from'] > $rule['valid_until']) {
                    $validator->errors()->add("rules.{$index}.valid_until", 'The valid_until date must be on or after valid_from.');
                }
            }
        });
    }
}
