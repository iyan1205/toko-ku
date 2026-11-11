<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal berulang (templat bulanan) dan jurnal balik otomatis.
 *
 *   recurring_journals      : templat; scheduler membuat jurnal setiap next_run_on tiba.
 *   journals.recurring_*    : jurnal hasil templat. Unik per templat + periode (YYYY-MM), jadi
 *                             satu bulan tidak pernah dibuat dua kali.
 *   journals.auto_reverse_on: jurnal manual (misal akrual) dibalik otomatis pada tanggal ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('description', 255);
            $table->unsignedTinyInteger('interval_months')->default(1);
            $table->unsignedTinyInteger('day_of_month');
            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->date('next_run_on')->nullable()->index();
            $table->date('last_run_on')->nullable();
            $table->boolean('auto_post')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('last_error', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recurring_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_journal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->date('auto_reverse_on')->nullable()->after('journal_date')->index();
            $table->foreignId('recurring_journal_id')->nullable()->after('reversal_of_id')->constrained()->nullOnDelete();
            $table->string('recurring_period', 7)->nullable()->after('recurring_journal_id');
            $table->unique(['recurring_journal_id', 'recurring_period'], 'journals_recurring_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropUnique('journals_recurring_period_unique');
            $table->dropConstrainedForeignId('recurring_journal_id');
            $table->dropIndex(['auto_reverse_on']);
            $table->dropColumn(['auto_reverse_on', 'recurring_period']);
        });
        Schema::dropIfExists('recurring_journal_lines');
        Schema::dropIfExists('recurring_journals');
    }
};
