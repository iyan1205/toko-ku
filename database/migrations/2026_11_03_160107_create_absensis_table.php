<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('status', 20)->default('hadir'); // hadir | terlambat | izin | sakit | alpa | cuti
            $table->timestamp('clock_in')->nullable();
            $table->timestamp('clock_out')->nullable();

            // Diisi saat pegawai absen sendiri. Kosong untuk catatan manual (izin/sakit/alpa dari admin).
            $table->string('device_type', 10)->nullable()->comment('web|mobile|tablet');
            $table->string('ip_address', 45)->nullable();
            $table->string('location', 50)->nullable()->comment('lintang,bujur dari GPS browser');

            $table->string('keterangan', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // admin yang mencatat/mengoreksi
            $table->timestamps();

            // Satu catatan per pegawai per hari. Juga mencegah absen masuk ganda bila tombol ditekan dua kali.
            $table->unique(['user_id', 'tanggal']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensis');
    }
};
