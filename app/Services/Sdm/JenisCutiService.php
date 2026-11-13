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
     * Buat jenis cuti standar (config sdm.standard_leave_types) yang belum ada. Jenis bernama sama yang
     * aturannya belum pernah diatur ikut dilengkapi; yang sudah diatur tidak diubah.
     *
     * @return array{created: int, updated: int}
     */
    public function lengkapiStandar(): array
    {
        $counts = ['created' => 0, 'updated' => 0];

        DB::transaction(function () use (&$counts) {
            foreach (config('sdm.standard_leave_types', []) as $standar) {
                $jenis = JenisCuti::query()->where('nama', $standar['nama'])->first();
                $aturan = array_diff_key($standar, array_flip(['nama', 'kuota_tahunan', 'status_absensi']));

                if ($jenis === null) {
                    JenisCuti::create($standar + ['is_active' => true]);
                    $counts['created']++;
                } elseif ($jenis->category === 'other' || ($jenis->quota_tiers === null && $jenis->min_service_months === 0 && $jenis->max_days_per_request === null && ! $jenis->requires_document)) {
                    if ($jenis->fill($aturan)->isDirty()) {
                        $jenis->save();
                        $counts['updated']++;
                    }
                }
            }
        });

        return $counts;
    }

    /**
     * @param  array{nama:string,kuota_tahunan?:?int,status_absensi:string,is_active:bool,tahap?:array<int, array{tipe:string,peran?:string,approver_id?:int|string}>}  $data
     */
    public function simpan(array $data, ?JenisCuti $jenis = null): JenisCuti
    {
        return DB::transaction(function () use ($data, $jenis) {
            $jenis ??= new JenisCuti;
            $tiers = collect($data['quota_tiers'] ?? [])->map(fn ($t) => ['months' => (int) $t['months'], 'days' => (int) $t['days']])->sortBy('months')->values()->all();
            $jenis->fill([
                'nama' => $data['nama'],
                // Saldo per masa kerja tanpa kuota dasar: kuota dasar = tingkat tertinggi (untuk daftar & saldo).
                'kuota_tahunan' => $data['kuota_tahunan'] ?? ($tiers !== [] ? max(array_column($tiers, 'days')) : null),
                'quota_tiers' => $tiers === [] ? null : $tiers,
                'status_absensi' => $data['status_absensi'],
                'is_active' => $data['is_active'],
            ])->fill(array_intersect_key($data, array_flip([
                'category', 'is_paid', 'requires_document', 'document_after_days', 'max_days_per_request', 'min_service_months', 'gender', 'working_days_only',
            ])));
            $jenis->category ??= 'other';
            $jenis->min_service_months ??= 0;
            $jenis->document_after_days ??= 0;
            $jenis->save();

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
