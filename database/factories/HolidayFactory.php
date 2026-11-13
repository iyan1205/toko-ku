<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'name' => 'Libur '.fake()->unique()->numerify('###'),
            'is_national' => true,
            'branch_id' => null,
        ];
    }
}
