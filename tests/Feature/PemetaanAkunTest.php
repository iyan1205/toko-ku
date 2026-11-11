<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\PurchaseService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Pemetaan akun jurnal otomatis (Pengaturan → Akuntansi) dan pemakaiannya di penjualan & pembelian. */
class PemetaanAkunTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private User $kasir;

    private Product $ayam; // jual 10.000, modal 7.000

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->ayam = Product::factory()->stok(20)->create(['selling_price' => 10000, 'purchase_price' => 7000]);
        Customer::umum();
        app(ChartOfAccountsService::class)->install();
    }

    private function akun(string $kode): Account
    {
        return Account::where('code', $kode)->sole();
    }

    /** Pemetaan saat ini sebagai isian formulir, dengan perubahan. @return array<string, mixed> */
    private function formulir(array $peran = [], array $metode = []): array
    {
        $roles = app(AccountMappingService::class)->current()->map(fn ($r) => $r['account']?->id)->all();

        return ['auto_journal' => '1', 'roles' => array_merge($roles, $peran), 'payments' => $metode];
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

    /** @return array<string, string> kode => "D 18000.00" */
    private function baris(Journal $journal): array
    {
        $hasil = [];
        foreach ($journal->lines()->with('account')->get() as $line) {
            $sisi = (float) $line->debit > 0 ? 'D' : 'K';
            $hasil[$line->account->code] = $sisi.' '.Uang::keDesimal(($hasil[$line->account->code] ?? 0) + Uang::sen($sisi === 'D' ? $line->debit : $line->credit));
        }
        ksort($hasil);

        return $hasil;
    }

    public function test_halaman_menampilkan_semua_peran_dengan_akun_bawaan(): void
    {
        $this->actingAs($this->pemilik)->get(route('settings.accounting.edit'))->assertOk()
            ->assertSee('Diskon penjualan')
            ->assertSee('Selisih persediaan (opname &amp; penyesuaian stok)', false)
            ->assertSee('Akun per metode pembayaran')
            ->assertSee('Ikut akun 1-1200 Bank');
    }

    public function test_qris_ke_rekening_khusus_dan_akun_sistem_ikut_berpindah(): void
    {
        $bca = Account::create(['code' => '1-1210', 'name' => 'Bank BCA', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $this->akun('1-1000')->id, 'is_cash_bank' => true]);
        $hppBaru = Account::create(['code' => '5-1100', 'name' => 'HPP Makanan', 'type' => 'cogs', 'normal_balance' => 'debit', 'parent_id' => $this->akun('5-0000')->id]);

        $this->actingAs($this->pemilik)->put(route('settings.accounting.update'), $this->formulir(['cogs' => $hppBaru->id], ['qris' => $bca->id]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($hppBaru->fresh()->is_system);
        $this->assertTrue($bca->fresh()->is_system);
        $this->assertFalse($this->akun('5-1000')->is_system, 'Akun yang tidak lagi dipetakan dilepas dari akun sistem.');

        $jual = $this->jual([['method' => 'qris', 'amount' => '20000']]);

        $this->assertSame([
            '1-1210' => 'D 20000.00',
            '1-1400' => 'K 14000.00',
            '4-1000' => 'K 20000.00',
            '5-1100' => 'D 14000.00',
        ], $this->baris(Journal::where('source_type', 'sale')->where('source_id', $jual->id)->sole()));
    }

    public function test_akun_yang_tidak_cocok_dengan_perannya_ditolak(): void
    {
        $this->actingAs($this->pemilik)->put(route('settings.accounting.update'), $this->formulir(
            ['sales' => $this->akun('6-1000')->id, 'payable' => null],
            ['transfer' => $this->akun('1-1300')->id],
        ))->assertSessionHasErrors(['roles.sales', 'roles.payable', 'payments.transfer']);

        $this->assertNull(setting('accounting.map.sales'), 'Tidak ada yang disimpan bila ada kesalahan.');
    }

    public function test_diskon_dipisah_ke_akun_diskon_penjualan_atau_digabung_bila_dikosongkan(): void
    {
        $diskon = ['items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '1500']], 'discount' => '500'];
        $jual = $this->jual([['method' => 'cash', 'amount' => '18000']], $diskon);

        $this->assertSame([
            '1-1100' => 'D 18000.00',
            '1-1400' => 'K 14000.00',
            '4-1000' => 'K 20000.00',
            '4-1200' => 'D 2000.00',
            '5-1000' => 'D 14000.00',
        ], $this->baris(Journal::where('source_id', $jual->id)->where('source_type', 'sale')->sole()));

        $this->actingAs($this->pemilik)->put(route('settings.accounting.update'), $this->formulir(['sales_discount' => null]))->assertSessionHasNoErrors();
        $jual = $this->jual([['method' => 'cash', 'amount' => '18000']], $diskon);

        $this->assertSame([
            '1-1100' => 'D 18000.00',
            '1-1400' => 'K 14000.00',
            '4-1000' => 'K 18000.00',
            '5-1000' => 'D 14000.00',
        ], $this->baris(Journal::where('source_id', $jual->id)->where('source_type', 'sale')->sole()));
    }

    public function test_diskon_dengan_harga_termasuk_pajak_tetap_seimbang(): void
    {
        setting(['tax.enabled' => true, 'tax.name' => 'PPN', 'tax.rate' => '11', 'tax.inclusive' => true]);

        $jual = $this->jual([['method' => 'cash', 'amount' => '18000']], ['items' => [['product_id' => $this->ayam->id, 'quantity' => '2', 'discount' => '2000']]]);
        $jurnal = Journal::where('source_id', $jual->id)->where('source_type', 'sale')->sole();
        $baris = $this->baris($jurnal);

        $this->assertSame($jurnal->lines->sum(fn ($l) => Uang::sen($l->debit)), $jurnal->lines->sum(fn ($l) => Uang::sen($l->credit)));
        $this->assertSame('K '.$jual->tax, $baris['2-1200']);
        $this->assertSame('D 1801.80', $baris['4-1200'], 'Hanya bagian diskon di luar pajak: 2.000 x 16.216,22 / 18.000.');
    }

    public function test_pembelian_memakai_akun_persediaan_yang_dipetakan(): void
    {
        $gudang = Account::create(['code' => '1-1410', 'name' => 'Persediaan Bahan Baku', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $this->akun('1-1000')->id]);
        $this->actingAs($this->pemilik)->put(route('settings.accounting.update'), $this->formulir(['inventory' => $gudang->id]))->assertSessionHasNoErrors();

        $pembelian = app(PurchaseService::class)->buat([
            'supplier_id' => Supplier::factory()->create()->id, 'warehouse_id' => Warehouse::default()->id,
            'purchase_date' => now()->format('Y-m-d'), 'due_date' => now()->addDays(30)->format('Y-m-d'),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => '7000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0', 'paid_amount' => '0', 'payment_method' => null, 'update_cost' => false,
        ], $this->pemilik);

        $this->assertSame(['1-1410' => 'D 70000.00', '2-1100' => 'K 70000.00'], $this->baris(Journal::where('source_type', 'purchase')->where('source_id', $pembelian->id)->sole()));
    }

    public function test_akun_kas_bank_yang_dipetakan_tidak_bisa_dilepas_tandanya(): void
    {
        $kas = $this->akun('1-1100');

        $this->actingAs($this->pemilik)->put(route('accounts.update', $kas), [
            'code' => $kas->code, 'name' => 'Kas Laci', 'type' => 'asset', 'is_active' => '1', 'is_header' => '0', 'is_cash_bank' => '0',
        ])->assertSessionHasErrors('is_cash_bank');

        $this->assertTrue($kas->fresh()->is_cash_bank);
    }
}
