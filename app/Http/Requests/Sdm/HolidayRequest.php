<?php

namespace App\Http\Requests\Sdm;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('holiday') ? 'sdm.holidays.update' : 'sdm.holidays.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $exists = Holiday::query()->whereDate('date', $this->date('date'))
                ->where('branch_id', $this->input('branch_id') ?: null)
                ->when($this->route('holiday'), fn ($q, Holiday $h) => $q->whereKeyNot($h->id))
                ->exists();
            if ($exists) {
                $validator->errors()->add('date', 'Tanggal ini sudah tercatat sebagai hari libur'.($this->input('branch_id') ? ' cabang ini.' : ' nasional.'));
            }
        }];
    }

    public function attributes(): array
    {
        return ['date' => 'tanggal', 'name' => 'nama', 'branch_id' => 'cabang'];
    }
}
