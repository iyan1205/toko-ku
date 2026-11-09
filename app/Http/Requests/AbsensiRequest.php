<?php

namespace App\Http\Requests;

use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Catatan absensi manual oleh admin (tambah dan ubah). */
class AbsensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('absensi') ? 'sdm.attendances.update' : 'sdm.attendances.create');
    }

    public function rules(): array
    {
        /** @var Absensi|null $absensi */
        $absensi = $this->route('absensi');
        $pakaiJam = in_array($this->input('status'), StatusAbsensi::valuesPakaiJam(), true);
        $statusPakaiJam = implode(',', StatusAbsensi::valuesPakaiJam());

        return [
            // Satu catatan per pegawai per tanggal. whereDate supaya sama di MySQL dan SQLite.
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('absensis', 'user_id')
                    ->where(fn ($q) => $q->whereDate('tanggal', (string) $this->input('tanggal')))
                    ->ignore($absensi?->id),
            ],
            // Izin/sakit boleh dicatat di muka; hadir/terlambat tidak boleh untuk tanggal mendatang.
            'tanggal' => array_filter(['required', 'date_format:Y-m-d', $pakaiJam ? 'before_or_equal:today' : null]),
            'status' => ['required', Rule::enum(StatusAbsensi::class)],
            'jam_masuk' => ["exclude_unless:status,{$statusPakaiJam}", 'required', 'date_format:H:i'],
            'jam_pulang' => ["exclude_unless:status,{$statusPakaiJam}", 'nullable', 'date_format:H:i', 'after:jam_masuk'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'pegawai',
            'tanggal' => 'tanggal',
            'jam_masuk' => 'jam masuk',
            'jam_pulang' => 'jam pulang',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'Pegawai ini sudah punya catatan absensi pada tanggal tersebut. Ubah catatan yang ada.',
            'tanggal.before_or_equal' => 'Kehadiran tidak dapat dicatat untuk tanggal yang belum lewat.',
            'jam_pulang.after' => 'Jam pulang harus setelah jam masuk.',
        ];
    }
}
