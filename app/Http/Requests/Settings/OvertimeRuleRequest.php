<?php

namespace App\Http\Requests\Settings;

use App\Models\OvertimeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Aturan upah lembur baru dengan tanggal berlaku. Tingkat ditulis "1:1,5; *:2". */
class OvertimeRuleRequest extends FormRequest
{
    private const TIER_FIELDS = ['workday_tiers', 'rest_day_tiers_6', 'rest_day_tiers_5'];

    public function authorize(): bool
    {
        return $this->user()->can('settings.sdm.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date', Rule::unique('overtime_rules', 'effective_from')],
            'mode' => ['required', Rule::in(array_keys(OvertimeRule::MODES))],
            'divisor' => ['required_if:mode,multiplier', 'nullable', 'integer', 'min:1', 'max:400'],
            'workday_tiers' => ['required_if:mode,multiplier', 'nullable', 'string', 'max:100'],
            'rest_day_tiers_6' => ['required_if:mode,multiplier', 'nullable', 'string', 'max:100'],
            'rest_day_tiers_5' => ['required_if:mode,multiplier', 'nullable', 'string', 'max:100'],
            'flat_rate' => ['required_if:mode,flat', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'rounding_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'min_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (self::TIER_FIELDS as $field) {
                if ($this->filled($field) && OvertimeRule::parseTiers($this->input($field)) === null) {
                    $validator->errors()->add($field, 'Format tingkat salah. Contoh: 1:1,5; *:2 (jam:pengali, dipisah titik koma; * = sisa jam).');
                }
            }
        }];
    }

    /** @return array<string, mixed> data siap disimpan */
    public function ruleData(): array
    {
        $data = $this->validated();
        foreach (self::TIER_FIELDS as $field) {
            $data[$field] = OvertimeRule::parseTiers($data[$field] ?? null) ?? [];
        }

        return $data + [
            'divisor' => $data['divisor'] ?? 173,
            'rounding_minutes' => (int) ($data['rounding_minutes'] ?? 0),
            'min_minutes' => (int) ($data['min_minutes'] ?? 0),
        ];
    }

    public function attributes(): array
    {
        return [
            'effective_from' => 'berlaku mulai', 'mode' => 'cara hitung', 'divisor' => 'pembagi upah sejam',
            'workday_tiers' => 'tingkat hari kerja', 'rest_day_tiers_6' => 'tingkat hari libur (6 hari kerja)',
            'rest_day_tiers_5' => 'tingkat hari libur (5 hari kerja)', 'flat_rate' => 'tarif per jam',
            'rounding_minutes' => 'pembulatan', 'min_minutes' => 'lembur minimal',
        ];
    }

    public function messages(): array
    {
        return ['effective_from.unique' => 'Sudah ada aturan yang berlaku mulai tanggal ini.'];
    }
}
