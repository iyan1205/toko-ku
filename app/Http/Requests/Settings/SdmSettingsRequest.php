<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SdmSettingsRequest extends FormRequest
{
    /** Nama isian form => kunci setting. */
    public const KEYS = [
        'workweek' => 'sdm.workweek',
        'default_in' => 'sdm.attendance.default_in',
        'default_out' => 'sdm.attendance.default_out',
        'late_tolerance' => 'sdm.attendance.late_tolerance',
        'early_tolerance' => 'sdm.attendance.early_tolerance',
        'require_selfie' => 'sdm.attendance.require_selfie',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('settings.sdm.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['require_selfie' => $this->boolean('require_selfie')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'workweek' => ['required', 'integer', Rule::in([5, 6])],
            'default_in' => ['nullable', 'date_format:H:i', 'required_with:default_out'],
            'default_out' => ['nullable', 'date_format:H:i', 'different:default_in'],
            'late_tolerance' => ['required', 'integer', 'min:0', 'max:240'],
            'early_tolerance' => ['required', 'integer', 'min:0', 'max:240'],
            'require_selfie' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'workweek' => 'hari kerja', 'default_in' => 'jam masuk', 'default_out' => 'jam pulang',
            'late_tolerance' => 'toleransi telat', 'early_tolerance' => 'toleransi pulang cepat',
        ];
    }
}
