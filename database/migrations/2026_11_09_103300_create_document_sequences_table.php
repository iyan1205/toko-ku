<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor urut terakhir per jenis dokumen per periode (hari/bulan/tahun/selamanya),
 * dipakai DocumentNumberService. Baris dikunci saat nomor dibuat sehingga dua kasir
 * tidak pernah mendapat nomor yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('period', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
