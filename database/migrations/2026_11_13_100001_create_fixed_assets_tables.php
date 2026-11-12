<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aset tetap.
 *
 *   asset_categories     : umur ekonomis (bulan), metode (straight_line | declining_balance + tarif tahunan %),
 *                          akun aset, akumulasi penyusutan, dan beban penyusutan.
 *   fixed_assets         : aset per cabang dengan lokasi dan penanggung jawab. Perolehan dijurnal saat dicatat
 *                          (D aset | K akun kredit, akumulasi awal untuk aset lama). status: active | disposed.
 *   depreciation_runs    : satu kali hitung penyusutan per periode (YYYY-MM) dan cabang = satu jurnal.
 *   asset_depreciations  : penyusutan per aset per periode (unik), bagian dari satu run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('method', 20)->default('straight_line');
            $table->decimal('declining_rate', 5, 2)->nullable();
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->date('acquisition_date')->index();
            $table->decimal('acquisition_cost', 18, 2);
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('method', 20);
            $table->decimal('declining_rate', 5, 2)->nullable();
            $table->date('depreciation_start');
            $table->decimal('opening_accumulated', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('location', 150)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('holder_name', 100)->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('serial_number', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 15)->default('active')->index();
            $table->date('disposed_at')->nullable();
            $table->string('disposal_type', 15)->nullable();
            $table->decimal('disposal_amount', 18, 2)->nullable();
            $table->foreignId('disposal_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('disposal_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period', 7)->index();
            $table->decimal('total', 18, 2)->default(0);
            $table->unsignedInteger('asset_count')->default(0);
            $table->string('status', 15)->default('posted');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('depreciation_run_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
