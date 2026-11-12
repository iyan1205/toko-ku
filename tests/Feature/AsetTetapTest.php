<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\CashBankAccount;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Assets\AssetCategoryService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Aset tetap: perolehan, penyusutan (garis lurus, saldo menurun, susulan, aset lama), pelepasan, pembatalan. */
class AsetTetapTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private AssetCategory $peralatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        app(AssetCategoryService::class)->installStandard();
        $this->peralatan = AssetCategory::where('code', 'KLP1')->sole();
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00'));
        $this->actingAs($this->akuntan);
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, string> */
    private function baris(Journal $journal): array
    {
        $hasil = [];
        foreach ($journal->lines()->with('account')->get() as $line) {
            $sisi = (float) $line->debit > 0 ? 'D' : 'K';
            $hasil[$line->account->code.' '.$sisi] = Uang::keDesimal(Uang::sen($hasil[$line->account->code.' '.$sisi] ?? 0) + Uang::sen($sisi === 'D' ? $line->debit : $line->credit));
        }
        ksort($hasil);

        return $hasil;
    }

    private function catat(array $ubah = []): FixedAsset
    {
        $this->post(route('fixed-assets.store'), array_merge([
            'name' => 'Freezer 500 L', 'asset_category_id' => $this->peralatan->id, 'acquisition_date' => '2026-11-10',
            'acquisition_cost' => '4800000', 'residual_value' => '0', 'credit_account_id' => $this->akun('1-1200'), 'location' => 'Dapur',
        ], $ubah))->assertSessionHasNoErrors();

        return FixedAsset::latest('id')->firstOrFail();
    }

    public function test_perolehan_dijurnal_dan_penyusutan_garis_lurus_tidak_dobel(): void
    {
        $aset = $this->catat();

        $this->assertSame('AST-0001', $aset->code);
        $this->assertSame(['1-1200 K' => '4800000.00', '1-2100 D' => '4800000.00'], $this->baris($aset->journals()->sole()));

        $this->post(route('depreciation.store'), ['periode' => '2026-11'])->assertSessionHasNoErrors();
        $this->post(route('depreciation.store'), ['periode' => '2026-11'])->assertSessionHasNoErrors();

        $run = DepreciationRun::sole();
        $this->assertSame('100000.00', (string) $run->total);
        $this->assertSame(['1-2900 K' => '100000.00', '6-1400 D' => '100000.00'], $this->baris($run->journals()->sole()));
        $this->assertSame('2026-11-30', $run->journals()->sole()->journal_date->toDateString());
        $this->assertSame(['100000.00', '4700000.00'], [(string) $aset->fresh()->accumulated_depreciation, $aset->fresh()->bookValue()]);

        $this->get(route('fixed-assets.show', $aset))->assertOk()->assertSee('Rp 4.700.000')->assertSee('Desember 2026');
    }

    public function test_bulan_terlewat_disusutkan_susulan_dalam_satu_jurnal(): void
    {
        $aset = $this->catat(['acquisition_date' => '2026-08-05']);

        Artisan::call('aset:penyusutan', ['--periode' => '2026-11']);

        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], $aset->depreciations()->pluck('period')->all());
        $this->assertSame('400000.00', (string) DepreciationRun::sole()->total);
    }

    public function test_saldo_menurun_dan_bulan_terakhir_menghabiskan_sisa(): void
    {
        $menurun = AssetCategory::create(['code' => 'SM', 'name' => 'Saldo menurun', 'useful_life_months' => 3, 'method' => 'declining_balance', 'declining_rate' => 50,
            'asset_account_id' => $this->akun('1-2100'), 'accumulated_account_id' => $this->akun('1-2900'), 'expense_account_id' => $this->akun('6-1400')]);
        $aset = $this->catat(['asset_category_id' => $menurun->id, 'acquisition_date' => '2026-09-01', 'acquisition_cost' => '1200000', 'residual_value' => '100000']);

        Artisan::call('aset:penyusutan', ['--periode' => '2026-11']);

        // Sep: 1.200.000 x 50% / 12 = 50.000; Okt: 1.150.000 x 50% / 12 = 47.916,67; Nov (bulan terakhir): sisa sampai nilai sisa.
        $this->assertSame(['50000.00', '47916.67', '1002083.33'], $aset->depreciations()->pluck('amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('100000.00', $aset->fresh()->bookValue());
    }

    public function test_aset_lama_dengan_akumulasi_awal(): void
    {
        $aset = $this->catat(['acquisition_date' => '2024-01-15', 'opening_accumulated' => '2200000', 'depreciation_start' => '2026-11',
            'credit_account_id' => $this->akun('3-9000')]);

        $jurnal = $aset->journals()->sole();
        $this->assertSame('2026-10-31', $jurnal->journal_date->toDateString());
        $this->assertSame(['1-2100 D' => '4800000.00', '1-2900 K' => '2200000.00', '3-9000 K' => '2600000.00'], $this->baris($jurnal));

        Artisan::call('aset:penyusutan', ['--periode' => '2026-11']);
        $this->assertSame(['2026-11'], $aset->depreciations()->pluck('period')->all(), 'Bulan sebelum mulai disusutkan tidak ikut.');
        $this->assertSame('2300000.00', (string) $aset->fresh()->accumulated_depreciation);
    }

    public function test_penjualan_aset_mencatat_laba_dan_butuh_penyusutan_sebelumnya(): void
    {
        $aset = $this->catat(['acquisition_date' => '2026-10-01']);
        $bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1-1200'))->sole();
        $jual = ['disposed_at' => '2026-12-02', 'disposal_type' => 'sold', 'disposal_amount' => '4750000', 'disposal_cash_bank_account_id' => $bank->id];

        $this->post(route('fixed-assets.dispose', $aset), $jual)->assertSessionHas('error');
        $this->post(route('depreciation.store'), ['periode' => '2026-11'])->assertSessionHasNoErrors();
        $this->post(route('fixed-assets.dispose', $aset), $jual)->assertSessionHasNoErrors();

        // Akumulasi 200.000 (Okt+Nov), nilai buku 4.600.000, dijual 4.750.000 → laba 150.000.
        $this->assertTrue($aset->fresh()->isDisposed());
        $this->assertSame(['1-1200 D' => '4750000.00', '1-2100 K' => '4800000.00', '1-2900 D' => '200000.00', '4-2100 K' => '150000.00'],
            $this->baris(Journal::where('source_event', 'disposed')->sole()));
        $this->post(route('depreciation.store'), ['periode' => '2026-12'])->assertSessionHasNoErrors();
        $this->assertSame(2, $aset->depreciations()->count(), 'Aset yang dilepas tidak disusutkan lagi.');
    }

    public function test_pembatalan_penyusutan_membalik_jurnal_dan_bisa_dihitung_ulang(): void
    {
        $aset = $this->catat();
        $this->post(route('depreciation.store'), ['periode' => '2026-11']);
        $run = DepreciationRun::sole();

        $this->post(route('fixed-assets.destroy', $aset), ['alasan' => 'Salah catat'])->assertSessionHas('error');
        $this->post(route('depreciation-runs.cancel', $run), ['alasan' => 'Salah cost center'])->assertSessionHasNoErrors();

        $this->assertTrue($run->fresh()->isCancelled());
        $this->assertSame(0, $aset->depreciations()->count());
        $this->assertSame('0.00', (string) $aset->fresh()->accumulated_depreciation);
        $this->assertSame(1, Journal::where('source_type', 'depreciation_run')->whereNotNull('reversal_of_id')->count());

        $this->post(route('fixed-assets.destroy', $aset), ['alasan' => 'Salah catat'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($aset);
        $this->assertSame(['1-1200 D' => '4800000.00', '1-2100 K' => '4800000.00'], $this->baris(Journal::where('source_event', 'deleted')->sole()));
    }

    public function test_tanpa_izin_aset_ditolak(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('fixed-assets.index'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Admin))->post(route('depreciation.store'), ['periode' => '2026-11'])->assertForbidden();
    }
}
