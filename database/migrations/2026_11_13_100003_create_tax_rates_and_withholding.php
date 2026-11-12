<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif PPh potong (PPh 23, PPh 4(2)) dengan tanggal berlaku, diatur di Pengaturan → Tarif Pajak.
 * Tarif untuk satu kode = baris dengan effective_from terakhir yang tidak melewati tanggal dokumen.
 * Nilai awal di bawah ini hanya titik mulai; pemilik wajib mencocokkannya dengan aturan terbaru.
 *
 * Juga: pemotongan PPh di tagihan pembelian manual (invoices.withholding_*) dan NPWP/NIK pelanggan
 * untuk ekspor faktur pajak keluaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);
            $table->string('code', 30)->index();
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->date('effective_from');
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['code', 'effective_from']);
        });

        $now = now();
        $company = DB::table('companies')->where('is_default', true)->value('id');
        foreach ([
            ['pph23', 'PPH23-JASA', 'PPh 23 jasa', '2.00', 'Jasa teknik, manajemen, konsultan, dan jasa lain (PMK 141/2015).'],
            ['pph23', 'PPH23-SEWA', 'PPh 23 sewa harta (selain tanah/bangunan)', '2.00', 'Misal sewa kendaraan atau peralatan.'],
            ['pph4_2', 'PPH42-SEWA-BGN', 'PPh 4(2) sewa tanah/bangunan', '10.00', 'Final, dari nilai bruto sewa.'],
            ['pph4_2', 'PPH42-KONSTRUKSI', 'PPh 4(2) jasa konstruksi (kecil)', '1.75', 'Pelaksana kualifikasi kecil (PP 9/2022). Tarif lain sesuai kualifikasi.'],
        ] as [$kind, $code, $name, $rate, $description]) {
            DB::table('tax_rates')->insert([
                'company_id' => $company, 'kind' => $kind, 'code' => $code, 'name' => $name, 'rate' => $rate,
                'effective_from' => '2022-01-01', 'description' => $description, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('withholding_code', 30)->nullable()->after('total');
            $table->string('withholding_name', 100)->nullable()->after('withholding_code');
            $table->string('withholding_kind', 10)->nullable()->after('withholding_name');
            $table->decimal('withholding_rate', 5, 2)->default(0)->after('withholding_kind');
            $table->decimal('withholding_base', 18, 2)->default(0)->after('withholding_rate');
            $table->decimal('withholding_amount', 18, 2)->default(0)->after('withholding_base');
            $table->string('withholding_slip_number', 50)->nullable()->after('withholding_amount');
            $table->date('withholding_slip_date')->nullable()->after('withholding_slip_number');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_id', 30)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('tax_id'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn([
            'withholding_code', 'withholding_name', 'withholding_kind', 'withholding_rate', 'withholding_base', 'withholding_amount', 'withholding_slip_number', 'withholding_slip_date',
        ]));
        Schema::dropIfExists('tax_rates');
    }
};
