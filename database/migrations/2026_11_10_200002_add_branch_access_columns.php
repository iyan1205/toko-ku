<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cabang milik perusahaan, dan akses pengguna ke cabang.
 *
 *   - users.all_branches = boleh mengakses semua cabang, termasuk cabang yang dibuat nanti.
 *     Bawaan true: pengguna lama tetap melihat semua data seperti sebelumnya.
 *   - branch_user = daftar cabang yang boleh diakses bila all_branches = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        DB::table('branches')->whereNull('company_id')
            ->update(['company_id' => DB::table('companies')->where('is_default', true)->value('id')]);

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_branches')->default(true)->after('is_active');
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_branches');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
