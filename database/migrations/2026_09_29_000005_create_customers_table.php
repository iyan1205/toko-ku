<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150)->index();
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();

            // Hanya diubah oleh modul piutang (Tahap 7), tidak lewat formulir pelanggan.
            $table->decimal('receivable_balance', 15, 2)->default(0);

            // Pelanggan "Umum" bawaan sistem untuk transaksi tanpa pelanggan.
            $table->boolean('is_default')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
