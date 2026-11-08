<?php

namespace App\Services\Sdm;

use App\Models\JenisCuti;
use Illuminate\Support\Facades\DB;

/**
 * Simpan jenis cuti beserta alur persetujuannya. Urutan tahap mengikuti urutan baris di form (1, 2, 3, ...).
 * Pengajuan yang sudah berjalan tidak terpengaruh karena tahapnya disalin saat diajukan.
 */
class JenisCutiService
{
    /**
     * @param  array{nama:string,kuota_tahunan?:?int,status_absensi:string,is_active:bool,tahap?:array<int, array{tipe:string,peran?:string,approver_id?:int|string}>}  $data
     */
    public function simpan(array $data, ?JenisCuti $jenis = null): JenisCuti
    {
        return DB::transaction(function () use ($data, $jenis) {
            $jenis ??= new JenisCuti;
            $jenis->fill([
                'nama' => $data['nama'],
                'kuota_tahunan' => $data['kuota_tahunan'] ?? null,
                'status_absensi' => $data['status_absensi'],
                'is_active' => $data['is_active'],
            ])->save();

            $jenis->tahap()->delete();

            foreach (array_values($data['tahap'] ?? []) as $i => $tahap) {
                $olehOrang = $tahap['tipe'] === 'orang';

                $jenis->tahap()->create([
                    'urutan' => $i + 1,
                    'peran' => $olehOrang ? null : $tahap['peran'],
                    'approver_id' => $olehOrang ? (int) $tahap['approver_id'] : null,
                ]);
            }

            return $jenis->load('tahap');
        });
    }
}
