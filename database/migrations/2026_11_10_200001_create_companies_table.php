<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perusahaan (badan usaha) pemilik cabang. Saat ini selalu satu perusahaan utama (is_default)
 * yang namanya mengikuti Pengaturan → Profil Toko. Disiapkan untuk multi-perusahaan (Fase 17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $storeName = DB::table('settings')->where('key', 'nama_toko')->value('value');

        DB::table('companies')->insert([
            'code' => 'UTAMA',
            'name' => is_string($storeName) && trim($storeName) !== '' ? mb_substr(trim($storeName), 0, 150) : config('app.name', 'Toko-ku'),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
