<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status dokumen jurnal (HasDocumentStatus): draft → submitted → approved → posted, atau cancelled.
 * Jurnal yang sudah ada (manual dan otomatis) berstatus posted. Hanya jurnal posted yang
 * dihitung di saldo akun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->string('status', 15)->default('posted')->after('description')->index();
            $table->timestamp('submitted_at')->nullable()->after('user_id');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('approved_by');
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('posted_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_by');
        });

        DB::table('journals')->whereNull('posted_at')->update([
            'posted_at' => DB::raw('created_at'),
            'posted_by' => DB::raw('user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'submitted_at', 'approved_at', 'posted_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
