<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Scopes\BranchScope;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Inventory\StockTransferService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Jurnal otomatis dari stok: selisih opname, penyesuaian manual, dan transfer antar cabang. */
class JurnalOtomatisStokTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Product $ayam; // modal 7.000

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'purchase_price' => 7000]);
        app(ChartOfAccountsService::class)->install();
    }

    /** @return array<string, array{0: string, 1: string}> kode akun => [debit, kredit] */
    private function baris(Journal $journal): array
    {
        return $journal->lines()->with('account')->get()
            ->groupBy(fn ($l) => $l->account->code)
            ->map(fn ($ls) => [number_format($ls->sum('debit'), 2, '.', ''), number_format($ls->sum('credit'), 2, '.', '')])
            ->sortKeys()->all();
    }

    public function test_selisih_stok_opname_dijurnal_ke_persediaan_dan_selisih_persediaan(): void
    {
        $nasi = Product::factory()->stok(10)->create(['name' => 'Nasi', 'purchase_price' => 3000]);

        $this->actingAs($this->admin)->post(route('stock-opnames.store'), [
            'aksi' => 'final', 'opname_date' => now()->format('Y-m-d'), 'note' => 'Opname akhir bulan',
            'items' => [
                $this->ayam->id => ['system_stock' => '20', 'physical_stock' => '17', 'reason' => 'Rusak'],   // kurang 3 x 7.000
                $nasi->id => ['system_stock' => '10', 'physical_stock' => '12', 'reason' => 'Salah hitung'], // lebih 2 x 3.000
            ],
        ])->assertSessionHasNoErrors();

        $opname = StockOpname::sole();
        $jurnal = Journal::where('source_type', 'stock_opname')->sole();

        $this->assertSame($opname->id, $jurnal->source_id);
        $this->assertSame('approved', $jurnal->source_event);
        $this->assertSame([
            '1-1400' => ['6000.00', '21000.00'],
            '5-2000' => ['21000.00', '6000.00'],
        ], $this->baris($jurnal));

        // Dari dokumen ke jurnal dan sebaliknya.
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->get(route('journals.show', $jurnal))->assertOk()->assertSee(route('stock-opnames.show', $opname));
        $this->get(route('stock-opnames.show', $opname))->assertOk()
            ->assertSee('Jurnal akuntansi')->assertSee(route('journals.show', $jurnal));
    }

    public function test_barang_rusak_dijurnal_sebagai_selisih_persediaan_dan_tertaut_ke_kartu_stok(): void
    {
        $mutasi = app(StockService::class)->sesuaikan($this->ayam, StockMovementType::Damaged, 'out', '2', 'Jatuh', $this->admin);

        $jurnal = Journal::where('source_type', 'stock_movement')->sole();
        $this->assertSame($mutasi->id, $jurnal->source_id);
        $this->assertStringContainsString('Barang rusak: Ayam Geprek', $jurnal->description);
        $this->assertSame(['1-1400' => ['0.00', '14000.00'], '5-2000' => ['14000.00', '0.00']], $this->baris($jurnal));

        $this->actingAs($this->buatPengguna(RoleName::Pemilik))->get(route('journals.show', $jurnal))->assertOk()
            ->assertSee('Kartu stok Ayam Geprek')
            ->assertSee(route('stock.movements', ['produk' => $this->ayam->id, 'gudang' => $mutasi->warehouse_id]));
    }

    public function test_transfer_di_dalam_satu_cabang_tidak_dijurnal(): void
    {
        $belakang = Warehouse::factory()->create(['name' => 'Gudang Belakang']);

        app(StockTransferService::class)->create([
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $belakang->id, 'transfer_date' => now()->format('Y-m-d'),
            'note' => null, 'items' => [['product_id' => $this->ayam->id, 'quantity' => '4']],
        ], $this->admin);

        $this->assertSame(0, Journal::where('source_type', 'stock_transfer')->count());
    }

    public function test_transfer_antar_cabang_dijurnal_di_kedua_cabang_dan_dibalik_saat_dibatalkan(): void
    {
        $utama = Branch::query()->where('is_default', true)->sole();
        $jabong = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true, 'company_id' => $utama->company_id]);
        $gudangJabong = Warehouse::factory()->create(['name' => 'Gudang Jabong', 'branch_id' => $jabong->id]);
        $this->actingAs($this->admin);

        $transfer = app(StockTransferService::class)->create([
            'from_warehouse_id' => Warehouse::default()->id, 'to_warehouse_id' => $gudangJabong->id, 'transfer_date' => now()->format('Y-m-d'),
            'note' => null, 'items' => [['product_id' => $this->ayam->id, 'quantity' => '4']], // 4 x 7.000
        ], $this->admin);

        $jurnal = Journal::withoutGlobalScope(BranchScope::class)->where('source_type', 'stock_transfer')->orderBy('id')->get();
        $this->assertSame(['transfer_out', 'transfer_in'], $jurnal->pluck('source_event')->all());
        $this->assertSame([$utama->id, $jabong->id], $jurnal->pluck('branch_id')->all());
        $this->assertSame(['1-1400' => ['0.00', '28000.00'], '1-1700' => ['28000.00', '0.00']], $this->baris($jurnal[0]));
        $this->assertSame(['1-1400' => ['28000.00', '0.00'], '1-1700' => ['0.00', '28000.00']], $this->baris($jurnal[1]));

        app(StockTransferService::class)->void(StockTransfer::sole(), 'Salah cabang tujuan', $this->admin);

        $pembalik = Journal::withoutGlobalScope(BranchScope::class)->whereNotNull('reversal_of_id')->orderBy('id')->get();
        $this->assertSame(['void_out', 'void_in'], $pembalik->pluck('source_event')->all());
        $this->assertSame([$utama->id, $jabong->id], $pembalik->pluck('branch_id')->all(), 'Jurnal pembalik ada di cabang jurnal yang dibalik.');
        $this->assertSame(0.0, (float) Account::where('code', '1-1700')->first()->lines()->sum('debit') - (float) Account::where('code', '1-1700')->first()->lines()->sum('credit'));
        $this->assertSame($transfer->id, $pembalik[0]->source_id);
    }
}
