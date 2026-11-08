<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'nama' => 'Shift '.fake()->unique()->numerify('###'),
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
            'is_active' => true,
        ];
    }

    public function jam(string $masuk, string $pulang): static
    {
        return $this->state(fn () => ['jam_masuk' => $masuk, 'jam_pulang' => $pulang]);
    }
}
