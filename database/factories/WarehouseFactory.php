<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'code' => 'G'.fake()->unique()->numerify('###'),
            'name' => 'Gudang '.fake()->unique()->city(),
            'address' => null,
            'is_active' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
