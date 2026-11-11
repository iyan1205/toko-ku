<?php

namespace Tests\Feature;

use App\Enums\CashTransactionType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\CashBank\BankReconciliationService;
use App\Services\CashBank\CashTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Rekonsiliasi bank: impor rekening koran, cocok otomatis (nominal + tanggal), manual, catat mutasi, laporan, kunci. */
class RekonsiliasiBankTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1-1200'))->sole();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->actingAs($this->akuntan);

        // Di buku: masuk 1.500.000 (3 Nov), keluar 750.000 (5 Nov), keluar 200.000 (29 Nov belum di koran... tanggal 19 Nov).
        $kas = app(CashTransactionService::class);
        $akun = fn (string $kode) => Account::where('code', $kode)->value('id');
        foreach ([['in', '2026-11-03', '3-1000', '1500000'], ['out', '2026-11-05', '6-1100', '750000'], ['out', '2026-11-19', '6-1200', '200000']] as [$jenis, $tanggal, $kode, $nilai]) {
            $kas->createPosted(CashTransactionType::from($jenis), [
                'transaction_date' => $tanggal, 'cash_bank_account_id' => $this->bank->id, 'description' => "Uji {$kode}",
                'lines' => [['account_id' => $akun($kode), 'amount' => $nilai]],
            ], $this->akuntan);
        }
    }

    private function impor(string $csv, array $ubah = []): TestResponse
    {
        return $this->post(route('bank-reconciliations.store'), array_merge([
            'cash_bank_account_id' => $this->bank->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-15', 'closing_balance' => '735000',
            'file' => UploadedFile::fake()->createWithContent('koran.csv', $csv),
        ], $ubah));
    }

    private const KORAN = "Tanggal,Keterangan,Referensi,Masuk,Keluar,Saldo\n"
        ."04/11/2026,Setoran modal,STR-1,1.500.000,,1500000\n"
        ."06/11/2026,Transfer sewa,TRF-2,,750000,750000\n"
        ."15/11/2026,Biaya administrasi,,,15000,735000\n";

    public function test_impor_mencocokkan_otomatis_berdasarkan_nominal_dan_tanggal(): void
    {
        $this->impor(self::KORAN)->assertSessionHasNoErrors()->assertSessionHas('success');

        $koran = BankStatement::sole();
        $baris = $koran->lines()->orderBy('line_date')->get();
        $this->assertSame(['1500000.00', '-750000.00', '-15000.00'], $baris->pluck('amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['auto', 'auto', null], $baris->pluck('match_type')->all());

        $laporan = app(BankReconciliationService::class)->report($koran);
        $this->assertSame(73500000, $laporan['bank_closing']);
        $this->assertSame(75000000, $laporan['book_closing'], 'Saldo buku per akhir periode (15 Nov) tidak termasuk 19 Nov.');
        $this->assertSame(1500000, $laporan['bank_out']);
        $this->assertSame(0, $laporan['difference']);
    }

    public function test_mutasi_yang_belum_tercatat_dicatat_sebagai_transaksi_kas_lalu_rekonsiliasi_dikunci(): void
    {
        $this->impor(self::KORAN);
        $koran = BankStatement::sole();
        $biaya = $koran->lines()->whereNull('journal_line_id')->sole();

        $this->post(route('bank-reconciliations.finalize', $koran))->assertSessionHas('error');
        $this->post(route('bank-reconciliations.record', [$koran, $biaya]), ['account_id' => Account::where('code', '6-1600')->value('id')])->assertSessionHasNoErrors();

        $transaksi = CashTransaction::where('type', 'out')->latest('id')->first();
        $this->assertSame('15000.00', (string) $transaksi->amount);
        $this->assertSame('2026-11-15', $transaksi->transaction_date->toDateString());
        $this->assertSame('created', $biaya->fresh()->match_type);

        $this->post(route('bank-reconciliations.finalize', $koran))->assertSessionHasNoErrors();
        $this->assertTrue($koran->fresh()->isFinalized());
        $this->delete(route('bank-reconciliations.unmatch', [$koran, $biaya]))->assertSessionHas('error');

        $this->get(route('bank-reconciliations.show', $koran))->assertOk()->assertSee('Dikunci');
        $this->get(route('bank-reconciliations.report', $koran))->assertOk();
    }

    public function test_cocok_manual_dan_lepas(): void
    {
        $this->impor("Tanggal,Keterangan,Masuk,Keluar\n10/11/2026,Setoran beda tanggal,1500000,\n", ['closing_balance' => '1500000']);
        $koran = BankStatement::sole();
        $baris = $koran->lines()->sole();
        $this->assertNull($baris->journal_line_id, 'Selisih 7 hari, di luar toleransi 3 hari.');

        $calon = app(BankReconciliationService::class)->candidates($baris)->first();
        $this->post(route('bank-reconciliations.match', [$koran, $baris]), ['journal_line_id' => $calon->id])->assertSessionHasNoErrors();
        $this->assertSame('manual', $baris->fresh()->match_type);

        $this->delete(route('bank-reconciliations.unmatch', [$koran, $baris]))->assertSessionHasNoErrors();
        $this->assertNull($baris->fresh()->journal_line_id);
    }

    public function test_berkas_salah_dan_mutasi_di_luar_periode_ditolak(): void
    {
        $this->impor("Nama,Nilai\nA,1\n")->assertSessionHasErrors('file');
        $this->impor("Tanggal,Keterangan,Masuk,Keluar\n20/12/2026,Di luar periode,1000,\n")->assertSessionHasErrors('file');

        $this->assertSame(0, BankStatement::count());
    }
}
