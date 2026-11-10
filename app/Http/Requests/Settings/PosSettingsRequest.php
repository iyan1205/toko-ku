<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class PosSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.pos.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['require_session' => $this->boolean('require_session')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['require_session' => ['boolean']];
    }
}
