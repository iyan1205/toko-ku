<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Laba Rugi, Neraca, Arus Kas (tidak langsung), dan Perubahan Ekuitas dari jurnal posted. */
class LaporanKeuanganTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private FinancialReportService $laporan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00'));
        $this->laporan = app(FinancialReportService::class);

        $jurnal = app(JournalService::class);
        foreach ([
            ['2026-01-05', 'Setoran modal', '1-1100', '3-1000', '10000000'],
            ['2026-02-10', 'Pinjaman bank', '1-1200', '2-2100', '5000000'],
            ['2026-03-01', 'Beli rak toko', '1-2100', '1-1100', '4000000'],
            ['2026-10-15', 'Prive', '3-2000', '1-1100', '200000'],
            ['2026-11-10', 'Penjualan', '1-1100', '4-1000', '3000000'],
            ['2026-11-10', 'HPP', '5-1000', '1-1400', '1200000'],
            ['2026-11-20', 'Sewa terutang', '6-1100', '2-1900', '500000'],
            ['2026-11-30', 'Penyusutan', '6-1400', '1-2900', '100000'],
        ] as [$tanggal, $ket, $debit, $kredit, $nilai]) {
            $jurnal->post($tanggal, $ket, [['account_id' => $this->akun($debit), 'debit' => $nilai], ['account_id' => $this->akun($kredit), 'credit' => $nilai]], $this->akuntan);
        }
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, list<int>> label => nilai */
    private function baris(array $report): array
    {
        return collect($report['rows'])->filter(fn ($r) => isset($r['values']))->mapWithKeys(fn ($r) => [$r['label'] => $r['values']])->all();
    }

    public function test_laba_rugi_per_bulan_dan_neraca_seimbang(): void
    {
        $lr = $this->baris($this->laporan->incomeStatement([], $this->laporan->periodColumns('single', '2026-01-01', '2026-12-03')));
        $this->assertSame([300000000], $lr['Jumlah pendapatan']);
        $this->assertSame([180000000], $lr['Laba kotor']);
        $this->assertSame([60000000], $lr['Jumlah beban']);
        $this->assertSame([120000000], $lr['Laba (rugi) bersih']);

        $bulanan = $this->laporan->incomeStatement([], $this->laporan->periodColumns('monthly', '2026-10-01', '2026-11-30'));
        $this->assertSame(['Oktober 2026', 'November 2026'], $bulanan['columns']);
        $this->assertSame([0, 120000000], $this->baris($bulanan)['Laba (rugi) bersih']);

        $banding = $this->laporan->incomeStatement([], $this->laporan->periodColumns('previous', '2026-11-01', '2026-11-30'), true);
        $this->assertSame(['November 2026', 'Oktober 2026', 'Selisih'], $banding['columns']);
        $this->assertSame([120000000, 0, 120000000], $this->baris($banding)['Laba (rugi) bersih']);

        $neraca = $this->laporan->balanceSheet([], '2026-12-03');
        $n = $this->baris($neraca);
        $this->assertTrue($neraca['check']['ok']);
        $this->assertSame([1650000000], $n['Jumlah aset']);
        $this->assertSame([550000000], $n['Jumlah liabilitas']);
        $this->assertSame([120000000], $n['Laba (rugi) tahun berjalan']);
        $this->assertSame([1100000000], $n['Jumlah ekuitas']);
        $this->assertSame([-10000000], $n['1-2900 Akumulasi Penyusutan'], 'Akun kontra tampil negatif.');
    }

    public function test_arus_kas_tidak_langsung_cocok_dengan_saldo_kas_dan_perubahan_ekuitas(): void
    {
        $arus = $this->laporan->cashFlow([], '2026-01-01', '2026-12-03');
        $a = $this->baris($arus);
        $this->assertTrue($arus['check']['ok'], $arus['check']['message']);
        $this->assertSame([300000000], $a['Kas bersih dari aktivitas operasi'], 'Laba 1,2 jt + persediaan 1,2 jt + sewa terutang 0,5 jt + penyusutan 0,1 jt.');
        $this->assertSame([-400000000], $a['Kas bersih dari aktivitas investasi']);
        $this->assertSame([1480000000], $a['Kas bersih dari aktivitas pendanaan']);
        $this->assertSame([1380000000], $a['Kas dan bank akhir periode']);

        $ekuitas = $this->baris($this->laporan->equityStatement([], '2026-01-01', '2026-12-03'));
        $this->assertSame([0, 1000000000, 0, 1000000000], $ekuitas['3-1000 Modal Pemilik']);
        $this->assertSame([0, 0, 20000000, -20000000], $ekuitas['3-2000 Prive Pemilik']);
        $this->assertSame([0, 120000000, 0, 120000000], $ekuitas['Laba (rugi) yang belum ditutup']);
        $this->assertSame(1100000000, $ekuitas['Jumlah ekuitas'][3]);
    }

    public function test_halaman_drill_down_dan_ekspor_berkop(): void
    {
        $this->actingAs($this->akuntan);
        $bukuBesar = route('ledger.index', ['akun' => $this->akun('4-1000'), 'dari' => '2026-01-01', 'sampai' => '2026-12-03']);

        $this->get(route('financial-reports.income', ['dari' => '2026-01-01', 'sampai' => '2026-12-03']))
            ->assertOk()->assertSee('Laba (rugi) bersih')->assertSee(e($bukuBesar), false);
        $this->get(route('financial-reports.balance', ['per' => '2026-12-03', 'banding' => 'tahun_lalu']))->assertOk()->assertSee('Seimbang')->assertSee('Per 31-12-2025');
        $this->get(route('financial-reports.cash-flow'))->assertOk()->assertSee('Cocok dengan saldo kas dan bank.');
        $this->get(route('financial-reports.equity'))->assertOk()->assertSee('Modal Pemilik');
        $this->get(route('financial-reports.index'))->assertOk()->assertSee(route('financial-reports.cash-flow'));

        $this->get(route('financial-reports.income', ['format' => 'xlsx']))->assertOk()->assertDownload('laba-rugi-20261203.xlsx');
        $this->get(route('financial-reports.balance', ['format' => 'pdf']))->assertOk()->assertDownload('neraca-20261203.pdf');

        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('financial-reports.income'))->assertForbidden();
    }
}
