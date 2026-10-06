<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'code' => 'SUP-' . fake()->unique()->numerify('9####'),
            'name' => 'PT ' . fake()->unique()->company(),
            'contact_name' => fake()->name(),
            'phone' => '08' . fake()->numerify('##########'),
        ];
    }
}
