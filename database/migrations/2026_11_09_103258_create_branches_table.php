<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cabang/outlet toko. Selalu ada tepat satu cabang utama (is_default), dibuat di sini
 * supaya aplikasi langsung punya cabang tanpa menjalankan seeder.
 * Stok tidak dipisah per cabang, melainkan per gudang (Fase 5a); gudang bisa ditautkan ke cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('address', 255)->nullable();
            $table->string('phone', 25)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('branches')->insert([
            'code' => 'UTAMA',
            'name' => 'Cabang Utama',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
