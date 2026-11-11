<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exports\Accounting\LedgerExport;
use App\Exports\Accounting\TrialBalanceExport;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Buku besar per akun, neraca saldo, dan cost center sebagai dimensi laporan. */
class BukuBesarTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private CostCenter $toko;

    private CostCenter $online;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->toko = CostCenter::factory()->create(['code' => 'TOKO', 'name' => 'Toko']);
        $this->online = CostCenter::factory()->create(['code' => 'ONLINE', 'name' => 'Online']);
        $this->travelTo(CarbonImmutable::parse('2026-12-05 10:00'));
        $this->actingAs($this->akuntan);

        $jurnal = app(JournalService::class);
        $jurnal->post('2026-10-05', 'Setoran modal', [$this->d('1-1100', 1000000), $this->k('3-1000', 1000000)], $this->akuntan);
        $jurnal->post('2026-11-03', 'Komisi titipan', [$this->d('1-1100', 500000, $this->online), $this->k('4-2000', 500000, $this->online)], $this->akuntan);
        $jurnal->post('2026-11-30', 'Listrik November', [$this->d('6-1200', 200000, $this->toko), $this->k('1-1100', 200000, $this->toko)], $this->akuntan);
        $jurnal->saveDraft('2026-11-04', 'Draf tidak dihitung', [$this->d('1-1100', 999), $this->k('3-1000', 999)], $this->akuntan);
    }

    private function id(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, mixed> */
    private function d(string $kode, int $rupiah, ?CostCenter $cc = null): array
    {
        return ['account_id' => $this->id($kode), 'debit' => (string) $rupiah, 'credit' => '0', 'cost_center_id' => $cc?->id];
    }

    /** @return array<string, mixed> */
    private function k(string $kode, int $rupiah, ?CostCenter $cc = null): array
    {
        return ['account_id' => $this->id($kode), 'debit' => '0', 'credit' => (string) $rupiah, 'cost_center_id' => $cc?->id];
    }

    /** @return array<string, mixed> */
    private function saringan(array $ubah = []): array
    {
        return array_merge(['akun' => $this->id('1-1100'), 'dari' => '2026-11-01', 'sampai' => '2026-11-30', 'nol' => false], $ubah);
    }

    public function test_buku_besar_saldo_awal_mutasi_dan_saldo_berjalan_hanya_dari_jurnal_posted(): void
    {
        $list = new LedgerExport($this->saringan());

        $this->assertSame(Uang::sen(1000000), $list->openingBalance());
        $this->assertSame(['debit' => Uang::sen(500000), 'credit' => Uang::sen(200000), 'closing' => Uang::sen(1300000)], $list->totals());

        // Saldo berjalan dihitung database di atas semua baris, jadi tetap benar di halaman berikutnya.
        $keDua = $list->query()->offset(1)->limit(1)->get()->sole();
        $this->assertSame('Listrik November', $keDua->journal_description);
        $this->assertSame(Uang::sen(1300000), $list->runningBalance($keDua));

        $this->get(route('ledger.index', $this->saringan()))->assertOk()
            ->assertSee('Saldo awal')
            ->assertSeeInOrder(['Rp 1.000.000', 'Komisi titipan', 'Rp 1.500.000', 'Listrik November', 'Rp 1.300.000'])
            ->assertDontSee('Draf tidak dihitung');
    }

    public function test_akun_bersaldo_normal_kredit_dihitung_kredit_dikurangi_debit(): void
    {
        $list = new LedgerExport($this->saringan(['akun' => $this->id('3-1000'), 'dari' => '2026-12-01', 'sampai' => '2026-12-05']));

        $this->assertSame(Uang::sen(1000000), $list->openingBalance());
    }

    public function test_saringan_cost_center_dan_tanpa_cost_center(): void
    {
        $online = new LedgerExport($this->saringan(['cost_center' => (string) $this->online->id]));
        $this->assertSame(['Komisi titipan'], $online->query()->get()->pluck('journal_description')->all());

        $tanpa = new LedgerExport($this->saringan(['dari' => '2026-10-01', 'cost_center' => 'tanpa']));
        $this->assertSame(['Setoran modal'], $tanpa->query()->get()->pluck('journal_description')->all());

        $this->get(route('trial-balance.index', ['dari' => '2026-11-01', 'sampai' => '2026-11-30', 'cost_center' => $this->toko->id]))->assertOk()
            ->assertSee('Beban Listrik, Air &amp; Internet', false)
            ->assertDontSee('Pendapatan Lain-lain')
            ->assertSee('Hanya baris jurnal dengan cost center terpilih');
    }

    public function test_neraca_saldo_seimbang_dan_bisa_diekspor(): void
    {
        $rows = (new TrialBalanceExport(['dari' => '2026-11-01', 'sampai' => '2026-11-30', 'nol' => false]))->query()->get()->keyBy('code');

        $this->assertSame(['1-1100', '3-1000', '4-2000', '6-1200'], $rows->keys()->all());
        $kas = TrialBalanceExport::figures($rows['1-1100']);
        $this->assertSame([Uang::sen(1000000), 0, Uang::sen(500000), Uang::sen(200000), Uang::sen(1300000), 0], array_values($kas));
        $this->assertSame(0, $rows->sum(fn ($a) => TrialBalanceExport::closing($a)), 'Total debit = total kredit.');

        $this->get(route('trial-balance.index', ['dari' => '2026-11-01', 'sampai' => '2026-11-30']))->assertOk()
            ->assertSee('Seimbang')
            ->assertSee(route('ledger.index', ['akun' => $this->id('1-1100'), 'dari' => '2026-11-01', 'sampai' => '2026-11-30']));

        Excel::fake();
        $this->get(route('trial-balance.export', ['dari' => '2026-11-01', 'sampai' => '2026-11-30', 'format' => 'xlsx']))->assertOk();
        Excel::assertDownloaded('neraca-saldo-20261205.xlsx');
        $this->get(route('ledger.export', ['akun' => $this->id('1-1100'), 'format' => 'pdf']))->assertOk();
        $this->get(route('ledger.export'))->assertSessionHasErrors('akun');
    }

    public function test_buku_besar_mengikuti_cabang_aktif_dan_saringan_cabang(): void
    {
        $utama = Branch::query()->where('is_default', true)->sole();
        $jabong = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true, 'company_id' => $utama->company_id]);
        app(JournalService::class)->post('2026-11-20', 'Kas Jabong', [$this->d('1-1100', 300000), $this->k('3-1000', 300000)], $this->akuntan, attributes: ['branch_id' => $jabong->id]);

        $this->put(route('current-branch.update'), ['branch_id' => $utama->id]);
        $this->get(route('ledger.index', $this->saringan()))->assertOk()->assertDontSee('Kas Jabong');

        $this->put(route('current-branch.update'), ['branch_id' => 'semua']);
        $this->get(route('ledger.index', $this->saringan()))->assertOk()->assertSee('Kas Jabong')->assertSee('Cabang Jabong');
        $this->get(route('ledger.index', $this->saringan(['cabang' => $jabong->id])))->assertOk()->assertSee('Kas Jabong')->assertDontSee('Komisi titipan');
    }

    public function test_cost_center_nonaktif_ditolak_dan_yang_terpakai_tidak_bisa_dihapus(): void
    {
        $this->toko->update(['is_active' => false]);

        $this->post(route('journals.store'), [
            'journal_date' => '2026-12-01', 'description' => 'Uji',
            'lines' => [$this->d('6-1200', 1000, $this->toko), $this->k('1-1100', 1000)],
        ])->assertSessionHasErrors('lines.0.cost_center_id');

        $this->delete(route('cost-centers.destroy', $this->toko))->assertSessionHas('error');
        $this->assertNotSoftDeleted($this->toko);

        $kosong = CostCenter::factory()->create();
        $this->delete(route('cost-centers.destroy', $kosong))->assertRedirect(route('cost-centers.index'));
        $this->assertSoftDeleted($kosong);

        $this->post(route('cost-centers.store'), ['code' => 'gdg', 'name' => 'Gudang', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('GDG', CostCenter::latest('id')->first()->code);
        $this->get(route('cost-centers.index'))->assertOk()->assertSee('Gudang');
    }

    public function test_tanpa_izin_buku_besar_ditolak(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin));

        $this->get(route('ledger.index'))->assertForbidden();
        $this->get(route('trial-balance.index'))->assertForbidden();
        $this->get(route('cost-centers.index'))->assertForbidden();
    }
}
