<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi kasir: dibuka dengan modal awal di satu gudang penjualan, ditutup dengan menghitung
 * uang fisik di laci. Nama tabel cash_sessions karena tabel shifts sudah dipakai shift kerja SDM.
 *
 * Rekap per metode dihitung dari tabel payments (cash_session_id) dan disalin ke summary saat
 * sesi ditutup, supaya rekap setoran tidak berubah setelah sesi selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open')->index(); // open | closed

            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->string('opening_note', 255)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('expected_cash', 15, 2)->nullable();   // modal + tunai masuk - tunai keluar
            $table->decimal('closing_cash', 15, 2)->nullable();    // uang fisik yang dihitung saat tutup
            $table->decimal('cash_difference', 15, 2)->nullable(); // closing_cash - expected_cash
            $table->json('summary')->nullable();                   // rekap per metode saat ditutup
            $table->string('closing_note', 255)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('verification_note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
