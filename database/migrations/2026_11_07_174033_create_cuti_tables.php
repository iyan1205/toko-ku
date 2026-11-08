<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenis_cutis', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->unsignedSmallInteger('kuota_tahunan')->nullable()->comment('hari per tahun; kosong = tanpa kuota');
            $table->string('status_absensi', 20)->default('cuti')->comment('status yang dicatat ke absensi: cuti|izin|sakit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Alur persetujuan per jenis cuti. Tiap tahap diisi peran ATAU orang tertentu.
        Schema::create('tahap_persetujuans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_cuti_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('urutan');
            $table->string('peran', 50)->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['jenis_cuti_id', 'urutan']);
        });

        Schema::create('cutis', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jenis_cuti_id')->constrained()->restrictOnDelete();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->unsignedSmallInteger('jumlah_hari');
            $table->string('alasan', 500);
            $table->string('status', 20)->default('menunggu'); // menunggu | disetujui | ditolak | dibatalkan
            // Urutan tahap yang sedang menunggu keputusan. Kosong bila pengajuan sudah selesai.
            $table->unsignedTinyInteger('tahap_aktif')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tanggal_mulai']);
            $table->index(['status', 'tahap_aktif']);
        });

        // Salinan alur saat pengajuan dibuat, supaya perubahan alur tidak mengubah pengajuan yang sedang berjalan.
        Schema::create('persetujuan_cutis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuti_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('urutan');
            $table->string('peran', 50)->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('menunggu'); // menunggu | disetujui | ditolak
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();

            $table->unique(['cuti_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_cutis');
        Schema::dropIfExists('cutis');
        Schema::dropIfExists('tahap_persetujuans');
        Schema::dropIfExists('jenis_cutis');
    }
};
