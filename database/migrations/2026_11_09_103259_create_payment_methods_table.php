<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan metode pembayaran di kasir: nama tampilan, aktif/nonaktif, dan urutan.
 * `code` = nilai enum App\Enums\PaymentMethod. Logika tiap metode (kembalian tunai, piutang)
 * tetap di kode; tabel ini hanya mengatur tampilan dan ketersediaannya.
 */
return new class extends Migration
{
    /** Isi awal sama dengan perilaku sebelumnya: semua aktif, urutan dan nama bawaan. */
    private const DEFAULTS = [
        'cash' => 'Tunai',
        'transfer' => 'Transfer',
        'qris' => 'QRIS',
        'debit' => 'Debit',
        'credit' => 'Kredit',
        'ewallet' => 'E-Wallet',
        'receivable' => 'Piutang',
    ];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label', 40);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $order = 0;
        foreach (self::DEFAULTS as $code => $label) {
            DB::table('payment_methods')->insert([
                'code' => $code,
                'label' => $label,
                'is_active' => true,
                'sort_order' => ++$order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
