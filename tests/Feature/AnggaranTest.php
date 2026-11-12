<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use App\Services\Assets\BudgetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Anggaran per akun per bulan dan laporan anggaran vs realisasi. */
class AnggaranTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00'));
        $this->actingAs($this->akuntan);
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    public function test_realisasi_dibandingkan_anggaran_dengan_peringatan(): void
    {
        $listrik = $this->akun('6-1200');
        $penjualan = $this->akun('4-2000');
        $this->post(route('budgets.store'), [
            'name' => 'Anggaran 2026', 'fiscal_year' => 2026,
            'lines' => [$listrik => [10 => '500000', 11 => '500000'], $penjualan => [11 => '2000000']],
        ])->assertSessionHasNoErrors();
        $budget = Budget::sole();

        $jurnal = app(JournalService::class);
        $jurnal->post('2026-10-20', 'Listrik Okt', [['account_id' => $listrik, 'debit' => '450000'], ['account_id' => $this->akun('1-1100'), 'credit' => '450000']], $this->akuntan);
        $jurnal->post('2026-11-20', 'Listrik Nov', [['account_id' => $listrik, 'debit' => '700000'], ['account_id' => $this->akun('1-1100'), 'credit' => '700000']], $this->akuntan);
        $jurnal->post('2026-11-25', 'Pendapatan lain', [['account_id' => $this->akun('1-1100'), 'debit' => '1500000'], ['account_id' => $penjualan, 'credit' => '1500000']], $this->akuntan);

        $laporan = app(BudgetService::class)->report($budget, 10, 11);
        $baris = collect($laporan['rows'])->keyBy(fn ($r) => $r['account']->code);

        $this->assertSame([100000000, 115000000, 115.0, true], [$baris['6-1200']['budget'], $baris['6-1200']['actual'], $baris['6-1200']['percent'], $baris['6-1200']['warning']]);
        $this->assertSame([200000000, 150000000, true], [$baris['4-2000']['budget'], $baris['4-2000']['actual'], $baris['4-2000']['warning']], 'Pendapatan di bawah target.');

        $oktober = collect(app(BudgetService::class)->report($budget, 10, 10)['rows'])->keyBy(fn ($r) => $r['account']->code);
        $this->assertFalse($oktober['6-1200']['warning']);

        $this->get(route('budgets.show', ['budget' => $budget, 'dari' => 10, 'sampai' => 11]))->assertOk()->assertSee('2 akun di luar anggaran')->assertSee('115%');
    }

    public function test_satu_anggaran_per_tahun_dan_cakupan(): void
    {
        $listrik = $this->akun('6-1200');
        $this->post(route('budgets.store'), ['name' => 'A', 'fiscal_year' => 2026, 'lines' => [$listrik => [1 => '100']]])->assertSessionHasNoErrors();
        $this->post(route('budgets.store'), ['name' => 'B', 'fiscal_year' => 2026, 'lines' => [$listrik => [1 => '100']]])->assertSessionHasErrors('fiscal_year');
        $this->post(route('budgets.store'), ['name' => 'Kosong', 'fiscal_year' => 2027, 'lines' => [$listrik => [1 => '']]])->assertSessionHasErrors('lines');

        $this->assertSame(1, Budget::count());
        $this->get(route('budgets.create', ['salin' => Budget::sole()->id]))->assertOk()->assertSee('value="2027"', false);
    }
}
