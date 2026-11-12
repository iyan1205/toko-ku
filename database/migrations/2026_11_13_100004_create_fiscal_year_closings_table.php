<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutup buku tahunan: satu baris per tahun. Jurnal penutupnya (satu per cabang) memindahkan saldo
 * akun pendapatan, HPP, dan beban ke Laba Ditahan per 31 Desember. Dibatalkan dengan jurnal pembalik.
 *
 * Juga accounts.cash_flow: kelompok arus kas (operating | investing | financing). Kosong = ditentukan
 * otomatis dari jenis dan kode akun (lihat FinancialReportService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_year_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('status', 15)->default('posted');
            $table->decimal('net_income', 18, 2)->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['fiscal_year', 'status']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('cash_flow', 15)->nullable()->after('is_cash_bank');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('cash_flow'));
        Schema::dropIfExists('fiscal_year_closings');
    }
};
