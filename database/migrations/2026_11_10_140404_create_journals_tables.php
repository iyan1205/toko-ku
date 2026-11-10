<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal umum: header + baris (debit/kredit). Jurnal tidak pernah diubah atau dihapus;
 * koreksi memakai jurnal pembalik (reversal_of_id).
 *
 * Jurnal otomatis menyimpan dokumen asalnya (source_type/source_id) dan kejadiannya
 * (source_event: created, voided, approved). Kombinasi ketiganya unik, sehingga satu kejadian
 * tidak pernah dijurnal dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('journal_date')->index();
            $table->string('description', 255);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 20)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'source_event'], 'journals_source_event_unique');
            $table->index(['source_type', 'source_id'], 'journals_source_index');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
    }
};
