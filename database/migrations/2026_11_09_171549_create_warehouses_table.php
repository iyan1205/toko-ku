<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gudang/lokasi stok. Selalu ada tepat satu gudang utama (is_default), dibuat di sini
 * supaya stok yang sudah ada langsung punya tempat. Gudang bisa ditautkan ke cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('address', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('warehouses')->insert([
            'code' => 'UTAMA',
            'name' => 'Gudang Utama',
            'branch_id' => DB::table('branches')->where('is_default', true)->value('id'),
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
