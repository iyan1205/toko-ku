<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->time('jam_masuk');
            // Boleh lebih kecil dari jam_masuk: shift malam yang selesai keesokan harinya.
            $table->time('jam_pulang');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('jadwals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal');
            // Kosong = libur. Shift yang masih dipakai jadwal tidak dapat dihapus.
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'tanggal']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwals');
        Schema::dropIfExists('shifts');
    }
};
