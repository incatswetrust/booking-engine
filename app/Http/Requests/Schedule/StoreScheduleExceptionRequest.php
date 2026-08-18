<?php

namespace App\Http\Requests\Schedule;

use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleExceptionType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreScheduleExceptionRequest extends FormRequest
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
        /** @var resource $resource */
        $resource = $this->route('resource');

        return [
            'date' => [
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail) use ($resource) {
                    $exists = ScheduleException::where('resource_id', $resource->id)
                        ->whereDate('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('A schedule exception already exists for this resource on this date.');
                    }
                },
            ],
            'type' => ['required', new Enum(ScheduleExceptionType::class)],
            'start_time' => ['required_if:type,custom_hours', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_if:type,custom_hours', 'nullable', 'date_format:H:i', 'after:start_time'],
        ];
    }
}
