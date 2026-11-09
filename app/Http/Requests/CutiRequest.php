<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pengajuan cuti. Kuota, tanggal bentrok, dan pergantian tahun dicek di CutiService. */
class CutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.leaves.create');
    }

    public function rules(): array
    {
        return [
            'jenis_cuti_id' => ['required', 'integer', Rule::exists('jenis_cutis', 'id')->where('is_active', true)],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'jenis_cuti_id' => 'jenis cuti',
            'tanggal_mulai' => 'tanggal mulai',
            'tanggal_selesai' => 'tanggal selesai',
        ];
    }

    public function messages(): array
    {
        return [
            'jenis_cuti_id.exists' => 'Jenis cuti ini tidak tersedia.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }
}
