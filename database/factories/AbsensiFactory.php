<?php

namespace Database\Factories;

use App\Enums\PerangkatAbsensi;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absensi>
 */
class AbsensiFactory extends Factory
{
    protected $model = Absensi::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tanggal' => today(),
            'status' => StatusAbsensi::Hadir,
            'clock_in' => today()->setTime(8, 0),
            'clock_out' => null,
            'device_type' => PerangkatAbsensi::Web,
            'ip_address' => '127.0.0.1',
            'location' => null,
        ];
    }

    /** Catatan manual tanpa jam (izin/sakit/alpa). */
    public function tanpaJam(StatusAbsensi $status = StatusAbsensi::Izin): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'clock_in' => null,
            'clock_out' => null,
            'device_type' => null,
            'ip_address' => null,
        ]);
    }
}
