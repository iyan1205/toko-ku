<?php

namespace Database\Factories;

use App\Models\Jadwal;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Jadwal>
 */
class JadwalFactory extends Factory
{
    protected $model = Jadwal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tanggal' => today(),
            'shift_id' => Shift::factory(),
        ];
    }

    public function libur(): static
    {
        return $this->state(fn () => ['shift_id' => null]);
    }
}
