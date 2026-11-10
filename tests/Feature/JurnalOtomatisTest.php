<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournalService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Jurnal otomatis dari penjualan, pembelian, pembayaran, retur, dan pembatalan. */
class JurnalOtomatisTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private User $admin;

    private Product $ayam; // jual 10.000, modal 7.000

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->ayam = Product::factory()->stok(20)->create(['name' => 'Ayam Geprek', 'selling_price' => 10000, 'purchase_price' => 7000]);
        $this->supplier = Supplier::factory()->create();
        Customer::umum();
        app(ChartOfAccountsService::class)->install();
    }

    /** @param array<string, mixed> $ubah 2 ayam = 20.000 */
    private function jual(array $bayar, array $ubah = []): Sale
    {
        $this->actingAs($this->kasir)->postJson(route('sales.store'), array_merge([
            'customer_id' => null,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '0',
            'payments' => $bayar,
        ], $ubah))->assertCreated();

        return Sale::latest('id')->firstOrFail();
    }

    /** Pembelian langsung 10 ayam x 7.000 = 70.000. */
    private function beliLangsung(string $dibayar = '0'): Purchase
    {
        return app(PurchaseService::class)->buat([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::default()->id,
            'purchase_date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => $dibayar,
            'payment_method' => $dibayar === '0' ? null : 'cash', 'update_cost' => false,
        ], $this->admin);
    }

    /** @return array<string, string> kode akun => "D 22200.00" / "K 20000.00" */
    private function baris(Journal $journal): array
    {
        $hasil = [];
        foreach ($journal->lines()->with('account')->get() as $line) {
            $sisi = (float) $line->debit > 0 ? 'D' : 'K';
            $nilai = Uang::sen($sisi === 'D' ? $line->debit : $line->credit);
            $hasil[$line->account->code][$sisi] = ($hasil[$line->account->code][$sisi] ?? 0) + $nilai;
        }
        ksort($hasil);

        return array_map(fn (array $sisi) => implode(' / ', array_map(fn ($s, $v) => $s.' '.Uang::keDesimal($v), array_keys($sisi), $sisi)), $hasil);
    }

    private function jurnalDari(string $type, int $id, string $event = 'created'): Journal
    {
        return Journal::where('source_type', $type)->where('source_id', $id)->where('source_event', $event)->sole();
    }

    private function semuaJurnalSeimbang(): void
    {
        foreach (Journal::with('lines')->get() as $journal) {
            $this->assertSame(
                $journal->lines->sum(fn ($l) => Uang::sen($l->debit)),
                $journal->lines->sum(fn ($l) => Uang::sen($l->credit)),
                "Jurnal {$journal->number} tidak seimbang.",
            );
        }
    }

    // ---------------- Penjualan ----------------

    public function test_penjualan_tunai_berpajak_mencatat_kas_pendapatan_ppn_dan_hpp(): void
    {
        setting(['tax.enabled' => true, 'tax.name' => 'PPN', 'tax.rate' => '11', 'tax.inclusive' => false]);

        $jual = $this->jual([['method' => 'cash', 'amount' => '50000']]);

        $this->assertSame([
            '1-1100' => 'D 22200.00',
            '1-1400' => 'K 14000.00',
            '2-1200' => 'K 2200.00',
            '4-1000' => 'K 20000.00',
            '5-1000' => 'D 14000.00',
        ], $this->baris($this->jurnalDari('sale', $jual->id)));
        $this->assertSame(1, Journal::count(), 'Pembayaran saat transaksi tidak dijurnal terpisah.');
    }

    public function test_penjualan_split_dengan_sisa_piutang_lalu_pelunasannya(): void
    {
        $pelanggan = Customer::factory()->create();
        $jual = $this->jual([['method' => 'qris', 'amount' => '5000'], ['method' => 'cash', 'amount' => '3000']], [
            'customer_id' => $pelanggan->id, 'payment_method' => 'receivable',
        ]);

        $this->actingAs($this->admin)->post(route('receivables.store', $jual), [
            'amount' => '12000', 'method' => 'transfer', 'paid_at' => now()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            '1-1100' => 'D 3000.00',
            '1-1200' => 'D 5000.00',
            '1-1300' => 'D 12000.00',
            '1-1400' => 'K 14000.00',
            '4-1000' => 'K 20000.00',
            '5-1000' => 'D 14000.00',
        ], $this->baris($this->jurnalDari('sale', $jual->id)));
        $pelunasan = $jual->payments()->where('amount', '12000.00')->sole();
        $this->assertSame(['1-1200' => 'D 12000.00', '1-1300' => 'K 12000.00'], $this->baris($this->jurnalDari('payment', $pelunasan->id)));
        $this->assertSame(2, Journal::count());
    }

    public function test_pembatalan_penjualan_membuat_jurnal_pembalik(): void
    {
        $jual = $this->jual([['method' => 'cash', 'amount' => '20000']]);

        $this->actingAs($this->admin)->post(route('sales.void', $jual), ['alasan' => 'Salah input transaksi'])->assertSessionHasNoErrors();

        $asli = $this->jurnalDari('sale', $jual->id);
        $pembalik = $this->jurnalDari('sale', $jual->id, 'voided');
        $this->assertSame($asli->id, $pembalik->reversal_of_id);
        $this->assertSame([
            '1-1100' => 'K 20000.00',
            '1-1400' => 'D 14000.00',
            '4-1000' => 'D 20000.00',
            '5-1000' => 'K 14000.00',
        ], $this->baris($pembalik));
    }

    public function test_retur_penjualan_mencatat_retur_uang_kembali_dan_persediaan(): void
    {
        $jual = $this->jual([['method' => 'cash', 'amount' => '20000']]);
        $this->actingAs($this->admin)->post(route('sale-returns.store'), [
            'sale_id' => $jual->id, 'reason' => 'Ayam kurang matang', 'aksi' => 'approve', 'refund_method' => 'cash',
            'items' => [$jual->items()->sole()->id => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            '1-1100' => 'K 10000.00',
            '1-1400' => 'D 7000.00',
            '4-1100' => 'D 10000.00',
            '5-1000' => 'K 7000.00',
        ], $this->baris($this->jurnalDari('sale_return', SaleReturn::sole()->id, 'approved')));
    }

    // ---------------- Pembelian ----------------

    public function test_penerimaan_barang_dari_po_mencatat_persediaan_dan_hutang_lalu_pembayarannya(): void
    {
        $po = app(PurchaseOrderService::class)->create([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => Warehouse::default()->id, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
            'discount' => '0', 'tax' => '7700', 'other_cost' => '0',
        ], $this->admin, true);
        $receipt = app(GoodsReceiptService::class)->create($po, [
            'warehouse_id' => Warehouse::default()->id, 'received_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'items' => [$po->items()->sole()->id => '10'],
        ], $this->admin);
        $faktur = $receipt->purchase;

        app(PaymentService::class)->bayarPembelian($faktur, Uang::sen('50000'), PaymentMethod::Transfer, null, $this->admin);

        $this->assertSame([
            '1-1400' => 'D 70000.00',
            '1-1500' => 'D 7700.00',
            '2-1100' => 'K 77700.00',
        ], $this->baris($this->jurnalDari('purchase', $faktur->id)));
        $bayar = $faktur->payments()->sole();
        $this->assertSame(['1-1200' => 'K 50000.00', '2-1100' => 'D 50000.00'], $this->baris($this->jurnalDari('payment', $bayar->id)));
    }

    public function test_pembelian_langsung_dibayar_sebagian_menjadi_satu_jurnal(): void
    {
        $faktur = $this->beliLangsung('30000');

        $this->assertSame([
            '1-1100' => 'K 30000.00',
            '1-1400' => 'D 70000.00',
            '2-1100' => 'K 40000.00',
        ], $this->baris($this->jurnalDari('purchase', $faktur->id)));
        $this->assertSame(1, Journal::count(), 'Pembayaran saat pembelian tidak dijurnal terpisah.');
    }

    public function test_pembatalan_pembelian_membalik_jurnal_faktur_dan_pembayarannya(): void
    {
        $faktur = $this->beliLangsung();
        app(PaymentService::class)->bayarPembelian($faktur, Uang::sen('20000'), PaymentMethod::Cash, null, $this->admin);

        app(PurchaseService::class)->batalkan($faktur->fresh(), 'Salah input faktur', $this->admin);

        $this->assertSame(4, Journal::count(), 'Faktur + pembayaran, masing-masing dengan pembaliknya.');
        $this->assertSame(0, Journal::whereNull('reversal_of_id')->whereDoesntHave('reversal')->count());
        $this->semuaJurnalSeimbang();
    }

    public function test_retur_pembelian_mengurangi_hutang_dan_persediaan(): void
    {
        $faktur = $this->beliLangsung();

        $retur = app(PurchaseReturnService::class)->create($faktur, [
            'warehouse_id' => Warehouse::default()->id, 'return_date' => now()->toDateString(), 'reason' => 'Ayam rusak saat tiba',
            'items' => [$faktur->items()->sole()->id => '2'],
        ], $this->admin, true);

        $this->assertSame(['1-1400' => 'K 14000.00', '2-1100' => 'D 14000.00'], $this->baris($this->jurnalDari('purchase_return', $retur->id, 'approved')));
    }

    // ---------------- Pengaman ----------------

    public function test_jurnal_otomatis_tidak_dibuat_bila_dimatikan(): void
    {
        setting(['accounting.auto_journal' => false]);

        $this->jual([['method' => 'cash', 'amount' => '20000']]);
        $this->beliLangsung();

        $this->assertSame(0, Journal::count());
    }

    public function test_kejadian_yang_sama_tidak_dijurnal_dua_kali(): void
    {
        $jual = $this->jual([['method' => 'cash', 'amount' => '20000']]);

        $ulang = app(AutoJournalService::class)->forSale($jual);

        $this->assertSame($this->jurnalDari('sale', $jual->id)->id, $ulang->id);
        $this->assertSame(1, Journal::count());
    }

    public function test_jurnal_gagal_tidak_mengganggu_penjualan_dan_tercatat_di_log(): void
    {
        Account::where('code', '4-1000')->update(['is_active' => false]);

        $jual = $this->jual([['method' => 'cash', 'amount' => '20000']]);

        $this->assertModelExists($jual);
        $this->assertSame(0, Journal::count());
        $this->assertTrue(Activity::where('log_name', 'akuntansi')->where('event', 'auto_journal_failed')
            ->where('description', "Jurnal otomatis gagal dibuat untuk penjualan {$jual->number}")->exists());
    }

    public function test_tanpa_bagan_akun_jurnal_otomatis_tidak_berjalan_dan_tidak_mencatat_kegagalan(): void
    {
        Account::query()->update(['parent_id' => null]);
        Account::query()->forceDelete();

        $this->jual([['method' => 'cash', 'amount' => '20000']]);

        $this->assertSame(0, Journal::count());
        $this->assertFalse(Activity::where('event', 'auto_journal_failed')->exists());
    }
}
