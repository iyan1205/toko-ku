<?php

namespace App\Http\Requests\Settings;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Semua metode dikirim sekaligus; urutan baris = urutan tampil di kasir. */
class UpdatePaymentMethodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.payment-methods.update');
    }

    protected function prepareForValidation(): void
    {
        $methods = $this->input('methods');

        if (is_array($methods)) {
            $this->merge(['methods' => array_values(array_map(fn ($row) => is_array($row) ? [
                'code' => $row['code'] ?? null,
                'label' => is_string($row['label'] ?? null) ? trim($row['label']) : ($row['label'] ?? null),
                'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ] : $row, $methods))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'methods' => ['required', 'array', 'size:'.count(PaymentMethod::configurable())],
            'methods.*.code' => ['required', 'distinct', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Mixed])],
            'methods.*.label' => ['required', 'string', 'max:40'],
            'methods.*.is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['methods.*.label' => 'nama metode', 'methods.*.code' => 'metode'];
    }

    public function messages(): array
    {
        return ['methods.size' => 'Semua metode pembayaran harus dikirim. Muat ulang halaman lalu coba lagi.'];
    }

    /** Kasir butuh minimal satu metode selain Piutang (piutang hanya untuk pelanggan terdaftar). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $usable = collect($this->input('methods'))
                    ->filter(fn (array $row) => $row['is_active'] && $row['code'] !== PaymentMethod::Receivable->value);

                if ($usable->isEmpty()) {
                    $validator->errors()->add('methods', 'Aktifkan minimal satu metode selain Piutang.');
                }
            },
        ];
    }
}
