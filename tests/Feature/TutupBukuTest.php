<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\FiscalYearClosing;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Tutup buku: checklist tutup bulan, jurnal penutup tahun ke Laba Ditahan, pembatalan, dan izin membuka periode. */
class TutupBukuTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00'));

        $jurnal = app(JournalService::class);
        foreach ([
            ['2025-01-05', '1-1100', '3-1000', '10000000'],
            ['2025-06-10', '1-1100', '4-1000', '3000000'],
            ['2025-07-01', '6-1100', '1-1100', '1000000'],
        ] as [$tanggal, $debit, $kredit, $nilai]) {
            $jurnal->post($tanggal, 'Transaksi 2025', [['account_id' => $this->akun($debit), 'debit' => $nilai], ['account_id' => $this->akun($kredit), 'credit' => $nilai]], $this->akuntan);
        }
        $this->actingAs($this->akuntan);
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, list<int>> */
    private function neraca(string $per): array
    {
        return collect(app(FinancialReportService::class)->balanceSheet([], $per)['rows'])
            ->filter(fn ($r) => isset($r['values']))->mapWithKeys(fn ($r) => [$r['label'] => $r['values']])->all();
    }

    public function test_checklist_tutup_bulan_menandai_jurnal_draf(): void
    {
        app(JournalService::class)->saveDraft('2025-12-15', 'Koreksi belum diposting', [
            ['account_id' => $this->akun('6-1100'), 'debit' => '1000'], ['account_id' => $this->akun('1-1100'), 'credit' => '1000'],
        ], $this->akuntan);

        $this->get(route('closing.checklist', [2025, 12]))->assertOk()
            ->assertSee('1 jurnal masih draf/menunggu persetujuan.')
            ->assertSee('Kunci periode');
        $this->get(route('closing.checklist', [2025, 11]))->assertOk()
            ->assertDontSee('jurnal masih draf')
            ->assertSee('Rekonsiliasi bank sampai akhir bulan');
        $this->get(route('closing.index', ['tahun' => 2025]))->assertOk()->assertSee('Tutup tahun 2025');
    }

    public function test_tutup_tahun_memindahkan_laba_ke_laba_ditahan_lalu_bisa_dibatalkan(): void
    {
        $this->post(route('closing.close-year', 2026), ['kunci' => '1'])->assertSessionHas('error', 'Tahun 2026 belum berakhir.');

        $this->post(route('closing.close-year', 2025), ['kunci' => '1'])->assertSessionHas('success');
        $tutup = FiscalYearClosing::sole();
        $this->assertSame('2000000.00', $tutup->net_income);
        $this->assertSame(12, FiscalPeriod::where('status', 'closed')->count());

        $penutup = Journal::where('source_type', 'fiscal_year_closing')->sole();
        $this->assertSame('2025-12-31', $penutup->journal_date->toDateString());
        $baris = $penutup->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertEquals([3000000, 0], [(float) $baris['4-1000']->debit, (float) $baris['4-1000']->credit]);
        $this->assertEquals([0, 1000000], [(float) $baris['6-1100']->debit, (float) $baris['6-1100']->credit]);
        $this->assertEquals([0, 2000000], [(float) $baris['3-3000']->debit, (float) $baris['3-3000']->credit]);

        // Laba Rugi 2025 tetap utuh; Neraca memindahkan laba ke Laba Ditahan.
        $lr = collect(app(FinancialReportService::class)->incomeStatement([], [['label' => '2025', 'from' => '2025-01-01', 'to' => '2025-12-31']])['rows'])->firstWhere('label', 'Laba (rugi) bersih');
        $this->assertSame([200000000], $lr['values']);
        $n = $this->neraca('2025-12-31');
        $this->assertSame([200000000], $n['3-3000 Laba Ditahan']);
        $this->assertSame([0], $n['Laba (rugi) tahun berjalan']);
        $this->assertSame($n['Jumlah aset'], $n['Jumlah liabilitas dan ekuitas']);

        $this->post(route('closing.close-year', 2025))->assertSessionHas('error', 'Tahun 2025 sudah ditutup.');

        // Desember dikunci: batal tutup buku harus membuka Desember dulu.
        $this->post(route('closing.cancel-year', $tutup), ['alasan' => 'Ada faktur susulan'])->assertSessionHas('error');
        $this->post(route('fiscal-periods.reopen', [2025, 12]), ['alasan' => 'Faktur susulan'])->assertSessionHas('success');
        $this->post(route('closing.cancel-year', $tutup), ['alasan' => 'Ada faktur susulan'])->assertSessionHas('success');

        $this->assertTrue($tutup->fresh()->isCancelled());
        $this->assertSame(1, Journal::where('source_type', 'fiscal_year_closing')->whereNotNull('reversal_of_id')->count());
        $this->assertTrue(Activity::where('event', 'year_reopened')->exists());
        $n = $this->neraca('2026-01-10');
        $this->assertSame([200000000], $n['Laba tahun-tahun lalu (belum ditutup)']);
        $this->assertArrayNotHasKey('3-3000 Laba Ditahan', $n);
    }

    public function test_membuka_kembali_periode_dan_tutup_tahun_butuh_izin_tutup_buku(): void
    {
        $this->post(route('fiscal-periods.close', [2025, 12]))->assertSessionHasNoErrors();

        $staf = User::factory()->create();
        $staf->givePermissionTo('accounting.periods.view', 'accounting.periods.update', 'accounting.closing.view');
        $this->actingAs($staf)->get(route('fiscal-periods.index', ['tahun' => 2025]))->assertOk()->assertDontSee('Buka kembali');
        $this->post(route('fiscal-periods.reopen', [2025, 12]), ['alasan' => 'Koreksi saldo'])->assertForbidden();
        $this->post(route('closing.close-year', 2025))->assertForbidden();
        $this->get(route('closing.index', ['tahun' => 2025]))->assertOk()->assertDontSee('Kunci semua bulan');
        $this->assertTrue(FiscalPeriod::sole()->isClosed());
        $this->assertSame(0, FiscalYearClosing::count());
    }
}
