<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagan akun (COA) bertingkat. Akun induk (is_header) hanya pengelompokan dan tidak bisa
 * dipakai di jurnal. Akun sistem (is_system) dipakai jurnal otomatis, jadi tidak bisa dihapus
 * atau dinonaktifkan. Isi standar dipasang lewat ChartOfAccountsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('type', 15)->index();          // App\Enums\AccountType
            $table->string('normal_balance', 6);           // debit | credit
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_header')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
