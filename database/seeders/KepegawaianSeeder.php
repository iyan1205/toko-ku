<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Models\JenisCuti;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Jenis cuti bawaan beserta alur persetujuannya, dan contoh shift untuk pengembangan.
 * Aman dijalankan berulang kali: data yang sudah ada (termasuk alur yang sudah diubah pemilik) tidak ditimpa.
 */
class KepegawaianSeeder extends Seeder
{
    public function run(): void
    {
        $this->jenisCuti('Cuti Tahunan', 12, StatusAbsensi::Cuti, [RoleName::Admin, RoleName::SuperAdmin]);
        $this->jenisCuti('Izin', null, StatusAbsensi::Izin, [RoleName::Admin]);
        $this->jenisCuti('Sakit', null, StatusAbsensi::Sakit, [RoleName::Admin]);

        if (! app()->isProduction()) {
            Shift::firstOrCreate(['nama' => 'Pagi'], ['jam_masuk' => '08:00', 'jam_pulang' => '16:00']);
            Shift::firstOrCreate(['nama' => 'Siang'], ['jam_masuk' => '14:00', 'jam_pulang' => '22:00']);
        }
    }

    /** @param  array<int, RoleName>  $alur */
    private function jenisCuti(string $nama, ?int $kuota, StatusAbsensi $status, array $alur): void
    {
        $jenis = JenisCuti::firstOrCreate(['nama' => $nama], ['kuota_tahunan' => $kuota, 'status_absensi' => $status]);

        if (! $jenis->wasRecentlyCreated) {
            return;
        }

        foreach ($alur as $i => $peran) {
            $jenis->tahap()->create(['urutan' => $i + 1, 'peran' => $peran]);
        }
    }
}
