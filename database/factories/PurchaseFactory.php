<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Purchase> */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /** Hanya untuk keperluan tes data (mis. hutang supplier). Pembelian nyata dibuat lewat PurchaseService. */
    public function definition(): array
    {
        return [
            'number' => 'PUR-TES-' . fake()->unique()->numerify('#####'),
            'supplier_id' => Supplier::factory(),
            'purchase_date' => now()->toDateString(),
            'subtotal' => 100000,
            'discount' => 0,
            'tax' => 0,
            'other_cost' => 0,
            'grand_total' => 100000,
            'paid_amount' => 0,
            'balance' => 100000,
            'payment_status' => PaymentStatus::Unpaid,
            'status' => 'completed',
            'user_id' => User::factory(),
        ];
    }
}
