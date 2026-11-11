<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening kas & bank: data rekening (bank, nomor, pemilik, plafon kas kecil) yang menempel pada
 * satu akun kas/bank di bagan akun. Saldonya = saldo akun itu (jurnal posted).
 * Akun kas/bank yang sudah ada (Kas 1-1100, Bank 1-1200, dan yang ditandai pemilik) langsung
 * dibuatkan rekeningnya.
 *
 * kind: cash (kas/laci), bank (rekening bank/e-wallet), petty_cash (kas kecil dengan plafon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 15);
            $table->string('name', 100);
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_holder', 100)->nullable();
            $table->decimal('imprest_amount', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $company = DB::table('companies')->where('is_default', true)->value('id');
        $now = now();
        foreach (DB::table('accounts')->where('is_cash_bank', true)->where('is_header', false)->whereNull('deleted_at')->orderBy('code')->get() as $account) {
            DB::table('cash_bank_accounts')->insert([
                'company_id' => $company,
                'account_id' => $account->id,
                'kind' => str_starts_with($account->code, '1-11') ? 'cash' : 'bank',
                'name' => $account->name,
                'is_active' => (bool) $account->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_bank_accounts');
    }
};
