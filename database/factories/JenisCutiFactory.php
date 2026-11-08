<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Models\JenisCuti;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JenisCuti>
 */
class JenisCutiFactory extends Factory
{
    protected $model = JenisCuti::class;

    public function definition(): array
    {
        return [
            'nama' => 'Cuti '.fake()->unique()->numerify('###'),
            'kuota_tahunan' => null,
            'status_absensi' => StatusAbsensi::Cuti,
            'is_active' => true,
        ];
    }

    public function kuota(int $hari): static
    {
        return $this->state(fn () => ['kuota_tahunan' => $hari]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Alur persetujuan berurutan. Tiap tahap: RoleName (siapa pun dengan peran itu) atau User (orang tertentu).
     *
     * @param  array<int, RoleName|User>  $tahap
     */
    public function alur(array $tahap): static
    {
        return $this->afterCreating(function (JenisCuti $jenis) use ($tahap) {
            foreach (array_values($tahap) as $i => $approver) {
                $jenis->tahap()->create([
                    'urutan' => $i + 1,
                    'peran' => $approver instanceof RoleName ? $approver : null,
                    'approver_id' => $approver instanceof User ? $approver->id : null,
                ]);
            }
        });
    }
}
