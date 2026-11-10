<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'code' => 'KRY-'.fake()->unique()->numerify('9###'),
            'name' => fake()->name(),
            'employment_type' => EmploymentType::Permanent,
            'join_date' => now()->subYear(),
            'is_active' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['is_active' => false, 'end_date' => now()]);
    }
}
