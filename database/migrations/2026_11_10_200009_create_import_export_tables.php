<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kerangka impor/ekspor massal.
 *
 *   import_batches : satu berkas impor: hasil baca + validasi per baris (pratinjau), lalu hasil proses.
 *   export_files   : ekspor data besar yang dibuat queue job; berkasnya dihapus setelah kedaluwarsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('file_name', 255);
            $table->string('status', 15)->default('previewed'); // previewed | completed | cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->longText('rows'); // JSON: [{line, data, errors}]
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'user_id']);
        });

        Schema::create('export_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('export_class', 150);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->json('branch_ids')->nullable(); // saringan cabang pemintanya; null = semua
            $table->string('status', 15)->default('queued'); // queued | processing | completed | failed
            $table->string('disk', 20)->default('local');
            $table->string('path', 255)->nullable();
            $table->string('file_name', 200);
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
        Schema::dropIfExists('import_batches');
    }
};
