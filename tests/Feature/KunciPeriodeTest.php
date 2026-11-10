<?php

namespace Tests\Feature;

use App\Enums\FiscalPeriodStatus;
use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Kunci periode: bulan yang ditutup menolak transaksi bertanggal di dalamnya, lewat formulir maupun service. */
class KunciPeriodeTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private User $admin;

    private CarbonImmutable $bulanLalu;

    private Supplier $supplier;

    private Product $ayam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->bulanLalu = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $this->supplier = Supplier::factory()->create();
        $this->ayam = Product::factory()->stok(50)->create(['name' => 'Ayam Geprek', 'selling_price' => 15000, 'purchase_price' => 8000]);
        app(ChartOfAccountsService::class)->install();
    }

    private function tutup(CarbonImmutable $bulan): void
    {
        $this->actingAs($this->akuntan)
            ->post(route('fiscal-periods.close', [$bulan->year, $bulan->month]))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function pembelian(string $tanggal): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => $tanggal,
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'unit_price' => '8000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0',
            'paid_amount' => '16000', 'payment_method' => 'cash', 'update_cost' => '0',
        ];
    }

    public function test_akuntan_menutup_bulan_lalu_dan_tercatat_di_log(): void
    {
        $this->actingAs($this->akuntan)
            ->post(route('fiscal-periods.close', [$this->bulanLalu->year, $this->bulanLalu->month]), ['catatan' => 'Stok opname selesai'])
            ->assertRedirect(route('fiscal-periods.index', ['tahun' => $this->bulanLalu->year]))
            ->assertSessionHas('success');

        $periode = FiscalPeriod::sole();
        $this->assertSame(FiscalPeriodStatus::Closed, $periode->status);
        $this->assertSame($this->akuntan->id, $periode->closed_by);
        $this->assertSame('Stok opname selesai', $periode->note);
        $this->assertTrue(Activity::where('event', 'period_closed')->exists());

        $this->get(route('fiscal-periods.index', ['tahun' => $this->bulanLalu->year]))
            ->assertOk()
            ->assertSee('Ditutup')
            ->assertSee('Stok opname selesai');
    }

    public function test_bulan_yang_belum_berjalan_dan_bulan_yang_sudah_ditutup_tidak_bisa_ditutup(): void
    {
        $depan = CarbonImmutable::now()->addMonthNoOverflow();

        $this->actingAs($this->akuntan)
            ->post(route('fiscal-periods.close', [$depan->year, $depan->month]))
            ->assertSessionHas('error');
        $this->assertSame(0, FiscalPeriod::where('status', 'closed')->count());

        $this->tutup($this->bulanLalu);
        $this->post(route('fiscal-periods.close', [$this->bulanLalu->year, $this->bulanLalu->month]))
            ->assertSessionHas('error', 'Periode '.$this->bulanLalu->locale('id')->translatedFormat('F Y').' sudah ditutup.');
    }

    public function test_formulir_menolak_tanggal_di_periode_tertutup(): void
    {
        $this->tutup($this->bulanLalu);
        $pesan = 'Periode '.$this->bulanLalu->locale('id')->translatedFormat('F Y').' sudah ditutup. Transaksi bertanggal di bulan itu tidak bisa disimpan atau diubah.';

        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->pembelian($this->bulanLalu->addDays(9)->toDateString()))
            ->assertSessionHasErrors(['purchase_date' => $pesan]);
        $this->assertSame(0, Purchase::count());

        $this->post(route('purchases.store'), $this->pembelian(now()->toDateString()))->assertSessionHasNoErrors();
        $pembelian = Purchase::sole();

        // Pembayaran hutang bertanggal di bulan yang ditutup juga ditolak.
        $hutang = app(PurchaseService::class)->buat(array_merge($this->pembelian(now()->toDateString()), ['paid_amount' => '0', 'payment_method' => null, 'due_date' => now()->addWeek()->toDateString()]), $this->admin);
        $this->post(route('payables.store', $hutang), ['amount' => '1000', 'method' => 'cash', 'paid_at' => $this->bulanLalu->toDateString()])
            ->assertSessionHasErrors(['paid_at' => $pesan]);
        $this->assertSame(0, $hutang->payments()->count());
        $this->assertNotNull($pembelian);
    }

    public function test_service_jurnal_menolak_periode_tertutup_termasuk_dari_jalur_selain_formulir(): void
    {
        $this->tutup($this->bulanLalu);
        $baris = [
            ['account_id' => Account::where('code', '1-1100')->value('id'), 'debit' => 50000],
            ['account_id' => Account::where('code', '3-1000')->value('id'), 'credit' => 50000],
        ];

        try {
            app(JournalService::class)->post($this->bulanLalu->addDays(3), 'Koreksi', $baris, $this->akuntan);
            $this->fail('Jurnal ke periode tertutup seharusnya ditolak.');
        } catch (AturanBisnisException $e) {
            $this->assertStringContainsString('sudah ditutup', $e->getMessage());
        }

        $this->assertSame(0, Journal::count());
        $this->assertSame('JU', substr(app(JournalService::class)->post(now(), 'Koreksi', $baris, $this->akuntan)->number, 0, 2));
    }

    public function test_bulan_berjalan_yang_ditutup_menghentikan_kasir_tetapi_dokumen_lama_tetap_bisa_dibatalkan(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $data = ['items' => [['product_id' => $this->ayam->id, 'quantity' => '1', 'discount' => '0']], 'payment_method' => 'cash', 'paid_amount' => '15000'];

        // Penjualan bulan lalu, lalu bulan lalu ditutup: pembatalannya dibukukan bulan ini.
        $this->travelTo($this->bulanLalu->addDays(14));
        $lama = app(SaleService::class)->buat($data, $kasir);
        $this->travelBack();
        $this->tutup($this->bulanLalu);

        $this->actingAs($this->admin)->post(route('sales.void', $lama), ['alasan' => 'Salah input pesanan'])->assertSessionHasNoErrors();
        $this->assertSame('voided', $lama->fresh()->status->value);
        $pembalik = Journal::whereNotNull('reversal_of_id')->where('source_type', 'sale')->firstOrFail();
        $this->assertSame(now()->toDateString(), $pembalik->journal_date->toDateString());

        // Bulan berjalan ditutup: kasir tidak bisa berjualan.
        $this->tutup(CarbonImmutable::now()->startOfMonth());
        $this->actingAs($kasir)->post(route('sales.store'), $data)->assertSessionHas('error');
        $this->assertSame(1, Sale::count());
    }

    public function test_membuka_kembali_wajib_beralasan_lalu_transaksi_bisa_lagi(): void
    {
        $this->tutup($this->bulanLalu);
        $url = route('fiscal-periods.reopen', [$this->bulanLalu->year, $this->bulanLalu->month]);

        $this->post($url, ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->assertTrue(FiscalPeriod::sole()->isClosed());

        $this->post($url, ['alasan' => 'Faktur supplier susulan'])->assertSessionHas('success');
        $periode = FiscalPeriod::sole();
        $this->assertSame(FiscalPeriodStatus::Open, $periode->status);
        $this->assertSame($this->akuntan->id, $periode->reopened_by);
        $this->assertTrue(Activity::where('event', 'period_reopened')->where('properties->alasan', 'Faktur supplier susulan')->exists());

        $this->actingAs($this->admin)
            ->post(route('purchases.store'), $this->pembelian($this->bulanLalu->addDays(9)->toDateString()))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Purchase::count());
    }

    public function test_hanya_pemegang_izin_yang_bisa_melihat_dan_mengubah_kunci_periode(): void
    {
        $this->actingAs($this->admin)->get(route('fiscal-periods.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('fiscal-periods.close', [$this->bulanLalu->year, $this->bulanLalu->month]))->assertForbidden();
        $this->assertSame(0, FiscalPeriod::count());

        $this->actingAs($this->akuntan)->get(route('fiscal-periods.index'))->assertOk()->assertSee('Tutup periode');
    }
}
