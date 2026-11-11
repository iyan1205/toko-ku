<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost center / departemen: dimensi laporan opsional di setiap baris jurnal (buku besar dan
 * neraca saldo bisa disaring per cost center). Boleh dihubungkan ke departemen SDM agar beban
 * gaji nanti masuk ke cost center departemen karyawannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('account_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
        Schema::dropIfExists('cost_centers');
    }
};
