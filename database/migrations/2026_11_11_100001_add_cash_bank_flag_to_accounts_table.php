<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda akun kas/bank di bagan akun. Hanya akun bertanda ini yang bisa dipilih sebagai akun
 * metode pembayaran (Pengaturan → Akuntansi) dan nanti dipakai modul Kas & Bank.
 * Kas (1-1100) dan Bank (1-1200) dari COA standar yang sudah terpasang langsung ditandai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash_bank')->default(false)->after('is_header');
        });

        DB::table('accounts')->whereIn('code', ['1-1100', '1-1200'])->where('is_header', false)->update(['is_cash_bank' => true]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash_bank');
        });
    }
};
