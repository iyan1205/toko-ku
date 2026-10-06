<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code' => 'CUS-' . fake()->unique()->numerify('9####'),
            'name' => fake()->unique()->name(),
            'phone' => '08' . fake()->numerify('##########'),
            'is_default' => false,
        ];
    }

    public function piutang(float|int $jumlah): static
    {
        return $this->state(fn () => ['receivable_balance' => $jumlah]);
    }
}
