<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventorySettingsRequest extends FormRequest
{
    /** Nama isian form => kunci setting. */
    public const KEYS = [
        'costing_method' => 'inventory.costing_method',
        'block_expired_sales' => 'inventory.block_expired_sales',
        'expiry_warning_days' => 'inventory.expiry_warning_days',
        'slow_moving_days' => 'inventory.slow_moving_days',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('settings.inventory.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['block_expired_sales' => $this->boolean('block_expired_sales')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'costing_method' => ['required', Rule::in(['average', 'fifo'])],
            'block_expired_sales' => ['boolean'],
            'expiry_warning_days' => ['required', 'integer', 'min:1', 'max:365'],
            'slow_moving_days' => ['required', 'integer', 'min:7', 'max:730'],
        ];
    }

    public function attributes(): array
    {
        return ['costing_method' => 'metode HPP', 'expiry_warning_days' => 'peringatan kedaluwarsa', 'slow_moving_days' => 'batas barang lambat laku'];
    }
}
