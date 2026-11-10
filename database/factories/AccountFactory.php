<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'code' => '6-'.fake()->unique()->numerify('9###'),
            'name' => 'Beban '.fake()->unique()->word(),
            'type' => AccountType::Expense,
            'normal_balance' => AccountType::Expense->normalBalance(),
            'is_header' => false,
            'is_active' => true,
        ];
    }

    public function induk(): static
    {
        return $this->state(fn () => ['is_header' => true]);
    }
}
