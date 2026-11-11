<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\GiroStatus;
use App\Enums\PurchaseStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\OpeningBalance;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\DueReminder;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\PurchaseService;
use App\Services\Receivables\PartyLedgerService;
use App\Services\Receivables\SettlementService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Piutang & hutang: faktur manual, pelunasan gabungan (sebagian, uang muka), nota kredit/debit,
 * giro, pembatalan, umur & kartu, faktur saldo awal, dan pengingat jatuh tempo. Semua dijurnal
 * lewat pemetaan akun.
 */
class PiutangHutangTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private Customer $siti;

    private Supplier $cv;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        app(ChartOfAccountsService::class)->install();
        Customer::umum();
        $this->siti = Customer::factory()->create(['name' => 'Bu Siti', 'payment_terms_days' => 14]);
        $this->cv = Supplier::factory()->create(['name' => 'CV Ayam']);
        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1-1200'))->sole();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00'));
        $this->actingAs($this->pemilik);
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
            $kunci = $line->account->code.' '.$sisi;
            $hasil[$kunci] = Uang::keDesimal(Uang::sen($hasil[$kunci] ?? 0) + Uang::sen($sisi === 'D' ? $line->debit : $line->credit));
        }
        ksort($hasil);

        return $hasil;
    }

    private function faktur(int $harga = 1000000, string $pajak = '11'): Invoice
    {
        $this->post(route('sales-invoices.store'), [
            'aksi' => 'post', 'customer_id' => $this->siti->id, 'invoice_date' => now()->format('Y-m-d'), 'tax_rate' => $pajak, 'description' => 'Katering',
            'lines' => [['description' => 'Katering', 'account_id' => $this->akun('4-2000'), 'quantity' => '1', 'unit_price' => (string) $harga]],
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    /** Penjualan kasir 5 x 10.000, dibayar 10.000, sisa 40.000 piutang. */
    private function jualPiutang(): Sale
    {
        $ayam = Product::factory()->stok(10)->create(['selling_price' => 10000, 'purchase_price' => 7000]);
        $this->postJson(route('sales.store'), [
            'customer_id' => $this->siti->id, 'items' => [['product_id' => $ayam->id, 'quantity' => '5', 'discount' => '0']],
            'discount' => '0', 'payments' => [['method' => 'cash', 'amount' => '10000']], 'payment_method' => 'receivable',
        ])->assertCreated();

        return Sale::latest('id')->firstOrFail();
    }

    /** @param array<string, string> $alokasi */
    private function terima(array $data, array $alokasi): Settlement
    {
        $this->post(route('receivable-settlements.store'), array_merge([
            'type' => 'receipt', 'customer_id' => $this->siti->id, 'settlement_date' => now()->format('Y-m-d'),
            'method' => 'transfer', 'cash_bank_account_id' => $this->bank->id,
        ], $data, ['allocations' => $alokasi]))->assertSessionHasNoErrors();

        return Settlement::latest('id')->firstOrFail();
    }

    public function test_faktur_penjualan_manual_dijurnal_dan_jatuh_tempo_ikut_termin_pelanggan(): void
    {
        $faktur = $this->faktur();

        $this->assertSame(DocumentStatus::Posted, $faktur->documentStatus());
        $this->assertSame(['1110000.00', '1110000.00'], [(string) $faktur->total, (string) $faktur->balance]);
        $this->assertSame('2026-11-25', $faktur->due_date->toDateString());
        $this->assertSame(['1-1300 D' => '1110000.00', '2-1200 K' => '110000.00', '4-2000 K' => '1000000.00'], $this->baris($faktur->journals()->sole()));
        $this->assertSame('1110000.00', (string) $this->siti->fresh()->receivable_balance);
    }

    public function test_satu_penerimaan_untuk_banyak_dokumen_sebagian_dan_kelebihan_menjadi_uang_muka(): void
    {
        $jual = $this->jualPiutang();
        $faktur = $this->faktur();

        $pelunasan = $this->terima(['amount' => '600000'], ["sale:{$jual->id}" => '40000', "invoice:{$faktur->id}" => '500000']);

        $this->assertSame(['0.00', '610000.00'], [(string) $jual->fresh()->balance, (string) $faktur->fresh()->balance]);
        $this->assertSame('60000.00', (string) $pelunasan->deposit_added);
        $this->assertSame(['1-1200 D' => '600000.00', '1-1300 K' => '540000.00', '2-1500 K' => '60000.00'], $this->baris($pelunasan->journals()->sole()));
        $this->assertSame(2, $pelunasan->payments()->count(), 'Setiap alokasi tercatat sebagai pembayaran dokumennya.');
        $this->assertSame(6000000, app(SettlementService::class)->depositBalance(true, $this->siti->id));

        // Uang muka dipakai di pelunasan berikutnya.
        $kedua = $this->terima(['amount' => '550000', 'deposit_applied' => '60000'], ["invoice:{$faktur->id}" => '610000']);
        $this->assertSame('0.00', (string) $faktur->fresh()->balance);
        $this->assertSame(['1-1200 D' => '550000.00', '1-1300 K' => '610000.00', '2-1500 D' => '60000.00'], $this->baris($kedua->journals()->sole()));
        $this->assertSame(0, app(SettlementService::class)->depositBalance(true, $this->siti->id));
    }

    public function test_alokasi_melebihi_uang_atau_sisa_ditolak(): void
    {
        $faktur = $this->faktur(100000, '0');

        $this->post(route('receivable-settlements.store'), [
            'type' => 'receipt', 'customer_id' => $this->siti->id, 'settlement_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'cash_bank_account_id' => $this->bank->id, 'amount' => '50000', 'allocations' => ["invoice:{$faktur->id}" => '80000'],
        ])->assertSessionHasErrors('allocations');
        $this->post(route('receivable-settlements.store'), [
            'type' => 'receipt', 'customer_id' => $this->siti->id, 'settlement_date' => now()->format('Y-m-d'), 'method' => 'cash',
            'cash_bank_account_id' => $this->bank->id, 'amount' => '500000', 'allocations' => ["invoice:{$faktur->id}" => '150000'],
        ])->assertSessionHas('error');

        $this->assertSame(0, Settlement::count());
    }

    public function test_nota_kredit_mengurangi_faktur_dan_faktur_terbayar_tidak_bisa_dibatalkan(): void
    {
        $faktur = $this->faktur(200000, '0');

        $this->post(route('receivable-settlements.store'), [
            'type' => 'credit_note', 'customer_id' => $this->siti->id, 'settlement_date' => now()->format('Y-m-d'),
            'counter_account_id' => $this->akun('4-1300'), 'description' => 'Kompensasi terlambat', 'allocations' => ["invoice:{$faktur->id}" => '25000'],
        ])->assertSessionHasNoErrors();

        $nota = Settlement::sole();
        $this->assertSame(['25000.00', '175000.00'], [(string) $faktur->fresh()->credited_amount, (string) $faktur->fresh()->balance]);
        $this->assertSame(['1-1300 K' => '25000.00', '4-1300 D' => '25000.00'], $this->baris($nota->journals()->sole()));

        $this->post(route('sales-invoices.cancel', $faktur), ['alasan' => 'Salah pelanggan'])->assertSessionHas('error');
        $this->assertSame(DocumentStatus::Posted, $faktur->fresh()->documentStatus());
    }

    public function test_pembatalan_pelunasan_mengembalikan_sisa_dan_membalik_jurnal(): void
    {
        $faktur = $this->faktur(300000, '0');
        $pelunasan = $this->terima(['amount' => '300000'], ["invoice:{$faktur->id}" => '300000']);
        $this->assertSame('0.00', (string) $faktur->fresh()->balance);

        $this->post(route('receivable-settlements.cancel', $pelunasan), ['alasan' => 'Transfer ditolak bank'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Cancelled, $pelunasan->fresh()->documentStatus());
        $this->assertSame('300000.00', (string) $faktur->fresh()->balance);
        $this->assertTrue($pelunasan->payments()->first()->dibatalkan());
        $this->assertSame(['1-1200 K' => '300000.00', '1-1300 D' => '300000.00'], $this->baris(Journal::where('source_event', 'voided')->sole()));
        $this->assertSame('300000.00', (string) $this->siti->fresh()->receivable_balance);
    }

    public function test_giro_diterima_lalu_cair_atau_ditolak(): void
    {
        $faktur = $this->faktur(500000, '0');
        $giro = $this->terima(['method' => 'giro', 'giro_number' => 'GR-01', 'giro_bank' => 'BCA', 'giro_due_date' => '2026-11-20', 'amount' => '200000', 'cash_bank_account_id' => null],
            ["invoice:{$faktur->id}" => '200000']);

        $this->assertSame(GiroStatus::Pending, $giro->giro_status);
        $this->assertSame(['1-1150 D' => '200000.00', '1-1300 K' => '200000.00'], $this->baris($giro->journals()->sole()));

        $this->post(route('giros.clear', $giro), ['cash_bank_account_id' => $this->bank->id, 'tanggal' => '2026-11-11'])->assertSessionHasNoErrors();
        $this->assertSame(GiroStatus::Cleared, $giro->fresh()->giro_status);
        $cair = Journal::where('source_event', 'giro_cleared')->sole();
        $this->assertSame(['1-1150 K' => '200000.00', '1-1200 D' => '200000.00'], $this->baris($cair));
        $this->post(route('receivable-settlements.cancel', $giro), ['alasan' => 'Coba batalkan'])->assertSessionHas('error');

        $ditolak = $this->terima(['method' => 'giro', 'giro_number' => 'GR-02', 'giro_due_date' => '2026-11-25', 'amount' => '300000', 'cash_bank_account_id' => null], ["invoice:{$faktur->id}" => '300000']);
        $this->assertSame('0.00', (string) $faktur->fresh()->balance);
        $this->post(route('giros.bounce', $ditolak), ['alasan' => 'Dana tidak cukup'])->assertSessionHasNoErrors();

        $this->assertSame(GiroStatus::Bounced, $ditolak->fresh()->giro_status);
        $this->assertSame(DocumentStatus::Cancelled, $ditolak->fresh()->documentStatus());
        $this->assertSame('300000.00', (string) $faktur->fresh()->balance);
        $this->get(route('giros.index', ['status' => 'bounced']))->assertOk()->assertSee('GR-02');
    }

    public function test_tagihan_dan_faktur_pembelian_dibayar_sekaligus_serta_nota_debit(): void
    {
        $ayam = Product::factory()->create(['purchase_price' => 7000]);
        $beli = app(PurchaseService::class)->buat([
            'supplier_id' => $this->cv->id, 'warehouse_id' => Warehouse::default()->id, 'purchase_date' => now()->format('Y-m-d'), 'due_date' => now()->addDays(10)->format('Y-m-d'),
            'items' => [['product_id' => $ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => '0', 'payment_method' => null, 'update_cost' => false,
        ], $this->pemilik);
        $this->post(route('purchase-bills.store'), [
            'aksi' => 'post', 'supplier_id' => $this->cv->id, 'invoice_date' => now()->format('Y-m-d'), 'reference' => 'CV-88',
            'lines' => [['description' => 'Servis freezer', 'account_id' => $this->akun('6-1900'), 'quantity' => '1', 'unit_price' => '250000']],
        ])->assertSessionHasNoErrors();
        $tagihan = Invoice::where('type', 'purchase')->sole();
        $this->assertSame(['2-1100 K' => '250000.00', '6-1900 D' => '250000.00'], $this->baris($tagihan->journals()->sole()));

        $this->post(route('payable-settlements.store'), [
            'type' => 'debit_note', 'supplier_id' => $this->cv->id, 'settlement_date' => now()->format('Y-m-d'),
            'counter_account_id' => $this->akun('5-1500'), 'allocations' => ["purchase:{$beli->id}" => '5000'],
        ])->assertSessionHasNoErrors();
        $this->post(route('payable-settlements.store'), [
            'type' => 'payment', 'supplier_id' => $this->cv->id, 'settlement_date' => now()->format('Y-m-d'), 'method' => 'transfer',
            'cash_bank_account_id' => $this->bank->id, 'amount' => '315000', 'allocations' => ["purchase:{$beli->id}" => '65000', "invoice:{$tagihan->id}" => '250000'],
        ])->assertSessionHasNoErrors();

        $bayar = Settlement::where('type', 'payment')->sole();
        $this->assertSame(['0.00', '0.00'], [(string) $beli->fresh()->balance, (string) $tagihan->fresh()->balance]);
        $this->assertSame(['1-1200 K' => '315000.00', '2-1100 D' => '315000.00'], $this->baris($bayar->journals()->sole()));
        $this->assertSame(['2-1100 D' => '5000.00', '5-1500 K' => '5000.00'], $this->baris(Settlement::where('type', 'debit_note')->sole()->journals()->sole()));

        $this->post(route('purchases.void', $beli), ['alasan' => 'Coba batalkan'])->assertSessionHas('error');
        $this->assertNotSame(PurchaseStatus::Voided, Purchase::find($beli->id)->status);
    }

    public function test_umur_dan_kartu_piutang_serta_piutang_saldo_awal_bisa_dilunasi(): void
    {
        $this->post(route('opening-balances.store'), [
            'aksi' => 'post', 'balance_date' => '2026-08-31',
            'receivables' => [['customer_id' => $this->siti->id, 'reference' => 'INV-LAMA-7', 'due_date' => '2026-09-15', 'amount' => '400000']],
        ])->assertSessionHasNoErrors();
        $lama = Invoice::where('kind', 'opening')->sole();
        $this->assertSame(['400000.00', 'INV-LAMA-7'], [(string) $lama->balance, $lama->reference]);
        $this->assertSame(0, $lama->journals()->count(), 'Jurnalnya ikut jurnal saldo awal.');

        $baru = $this->faktur(100000, '0');
        $aging = app(PartyLedgerService::class)->aging(true, 'faktur', CarbonImmutable::today());
        $this->assertSame([10000000, 0, 40000000, 0], $aging['rows'][0]['buckets'], 'Faktur saldo awal 31 Agu berumur 72 hari (61–90).');

        $this->terima(['amount' => '400000'], ["invoice:{$lama->id}" => '400000']);
        $this->assertSame('0.00', (string) $lama->fresh()->balance);

        $kartu = app(PartyLedgerService::class)->card(true, $this->siti->id, '2026-11-01', '2026-11-30');
        $this->assertSame([40000000, 10000000, 40000000, 10000000], [$kartu['opening'], $kartu['increase'], $kartu['decrease'], $kartu['closing']]);
        $this->get(route('receivable-reports.card', ['pelanggan' => $this->siti->id]))->assertOk()->assertSee('INV-LAMA-7')->assertSee($baru->number);

        // Saldo awal yang piutangnya sudah dilunasi tidak bisa dibatalkan.
        $this->post(route('opening-balances.cancel', OpeningBalance::sole()), ['alasan' => 'Salah input'])->assertSessionHas('error');
    }

    public function test_pengingat_jatuh_tempo_dikirim_ke_pengguna_yang_berwenang(): void
    {
        Notification::fake();
        $akuntan = $this->buatPengguna(RoleName::Akuntan);
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $this->post(route('sales-invoices.store'), [
            'aksi' => 'post', 'customer_id' => $this->siti->id, 'invoice_date' => '2026-11-01', 'due_date' => '2026-11-05',
            'lines' => [['description' => 'Sewa', 'account_id' => $this->akun('4-2000'), 'quantity' => '1', 'unit_price' => '90000']],
        ])->assertSessionHasNoErrors();

        Artisan::call('keuangan:pengingat-jatuh-tempo');

        Notification::assertSentTo($akuntan, DueReminder::class);
        Notification::assertNotSentTo($kasir, DueReminder::class);
    }

    public function test_saldo_awal_lama_dibuatkan_faktur_saldo_awal_sekali_saja(): void
    {
        $this->post(route('opening-balances.store'), [
            'aksi' => 'post', 'balance_date' => '2026-10-31',
            'payables' => [['supplier_id' => $this->cv->id, 'reference' => 'F-LAMA', 'due_date' => '', 'amount' => '250000']],
        ])->assertSessionHasNoErrors();
        // Seperti saldo awal yang diposting sebelum Fase 10: belum punya faktur saldo awal.
        Invoice::query()->forceDelete();

        Artisan::call('keuangan:faktur-saldo-awal');
        Artisan::call('keuangan:faktur-saldo-awal');

        $hutang = Invoice::sole();
        $this->assertSame(['opening', 'purchase', '250000.00', 'F-LAMA'], [$hutang->kind, $hutang->type->value, (string) $hutang->balance, $hutang->reference]);
        $this->assertSame(OpeningBalance::sole()->id, $hutang->opening_balance_id);
    }
}
