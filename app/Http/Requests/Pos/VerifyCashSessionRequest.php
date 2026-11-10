<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/** Verifikasi setoran sesi oleh atasan. Hak verifikasi dicek CashSessionPolicy. */
class VerifyCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sessions.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['verification_note' => ['nullable', 'string', 'max:255']];
    }

    public function attributes(): array
    {
        return ['verification_note' => 'catatan verifikasi'];
    }
}
