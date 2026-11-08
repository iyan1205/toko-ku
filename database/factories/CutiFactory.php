<?php

namespace Database\Factories;

use App\Enums\StatusCuti;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pengajuan tanpa tahap persetujuan. Untuk alur persetujuan, ajukan lewat CutiService.
 *
 * @extends Factory<Cuti>
 */
class CutiFactory extends Factory
{
    protected $model = Cuti::class;

    public function definition(): array
    {
        return [
            'nomor' => 'CT-'.fake()->unique()->numerify('#####'),
            'user_id' => User::factory(),
            'jenis_cuti_id' => JenisCuti::factory(),
            'tanggal_mulai' => today(),
            'tanggal_selesai' => today(),
            'jumlah_hari' => 1,
            'alasan' => 'Keperluan keluarga',
            'status' => StatusCuti::Disetujui,
            'tahap_aktif' => null,
        ];
    }

    public function tanggal(string $mulai, string $selesai, int $hari): static
    {
        return $this->state(fn () => ['tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai, 'jumlah_hari' => $hari]);
    }
}
