<?php

namespace App\Http\Requests;

use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Isi grid jadwal satu minggu: jadwal[user_id][Y-m-d] = id shift | "libur" | "" (kosong). */
class JadwalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.schedules.update');
    }

    public function rules(): array
    {
        $nilaiSah = Shift::query()->pluck('id')->map(fn ($id) => (string) $id)->push('libur')->all();

        return [
            'minggu' => ['required', 'date_format:Y-m-d'],
            'jadwal' => ['nullable', 'array'],
            'jadwal.*' => ['array'],
            'jadwal.*.*' => ['nullable', 'string', Rule::in($nilaiSah)],
        ];
    }

    /** Pegawai harus pegawai aktif dan tanggal harus berada di minggu yang sedang dibuka. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $senin = CarbonImmutable::parse($this->input('minggu'))->startOfWeek(CarbonInterface::MONDAY);
                $tanggalSah = collect(range(0, 6))->map(fn (int $i) => $senin->addDays($i)->toDateString())->all();
                $pegawaiSah = User::query()->aktif()->pluck('id')->map(fn ($id) => (int) $id)->all();

                foreach ((array) $this->input('jadwal', []) as $userId => $hari) {
                    if (! in_array((int) $userId, $pegawaiSah, true) || array_diff(array_keys($hari), $tanggalSah) !== []) {
                        $validator->errors()->add('jadwal', 'Data jadwal tidak valid. Muat ulang halaman lalu coba lagi.');

                        return;
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return ['jadwal.*.*.in' => 'Shift yang dipilih tidak ditemukan. Muat ulang halaman lalu coba lagi.'];
    }
}
