<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Scopes\BranchScope;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\ChartOfAccountsService;
use App\Support\CurrentBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Data transaksi per cabang (BelongsToBranch + BranchScope): cabang dokumen baru, saringan
 * cabang aktif di topbar, "Semua cabang", dan akses cabang per pengguna.
 */
class CabangAktifTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private Branch $utama;

    private Branch $jabong;

    private Warehouse $gudangJabong;

    private Product $ayam;

    private Product $teh;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->utama = Branch::query()->where('is_default', true)->sole();
        $this->jabong = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true]);
        $this->jabong->forceFill(['company_id' => Company::default()->id])->save();
        $this->gudangJabong = Warehouse::factory()->create(['name' => 'Gudang Jabong', 'branch_id' => $this->jabong->id]);
        $this->ayam = Product::factory()->stok(20, $this->gudangJabong)->create(['name' => 'Ayam Geprek', 'selling_price' => 15000]);
        $this->teh = Product::factory()->stok(20)->create(['name' => 'Es Teh', 'selling_price' => 5000]);
    }

    private function pilihCabang(User $user, Branch|string $cabang): void
    {
        $this->actingAs($user)->put(route('current-branch.update'), ['branch_id' => $cabang instanceof Branch ? $cabang->id : $cabang])
            ->assertSessionHasNoErrors();
    }

    private function jual(User $kasir, Product $produk): Sale
    {
        $this->actingAs($kasir)->post(route('sales.store'), [
            'items' => [['product_id' => $produk->id, 'quantity' => '1', 'discount' => '0']],
            'payment_method' => 'cash',
            'paid_amount' => (string) (int) $produk->selling_price,
        ])->assertSessionHasNoErrors();

        return Sale::query()->withoutGlobalScope(BranchScope::class)->latest('id')->firstOrFail();
    }

    public function test_dokumen_baru_ikut_cabang_gudangnya_dan_turunannya_ikut_dokumen_asal(): void
    {
        app(ChartOfAccountsService::class)->install();
        $this->pilihCabang($this->pemilik, $this->jabong);

        $jual = $this->jual($this->pemilik, $this->ayam);

        $this->assertSame($this->gudangJabong->id, $jual->warehouse_id);
        $this->assertSame($this->jabong->id, $jual->branch_id);
        $this->assertSame(Company::default()->id, $jual->company_id);
        $this->assertSame([$this->jabong->id], $jual->payments()->withoutGlobalScope(BranchScope::class)->pluck('branch_id')->all());
        $this->assertSame($this->jabong->id, StockMovement::query()->withoutGlobalScope(BranchScope::class)->where('reference_id', $jual->id)->value('branch_id'));
        $this->assertSame($this->jabong->id, Journal::query()->withoutGlobalScope(BranchScope::class)->where('source_type', 'sale')->value('branch_id'));
    }

    public function test_daftar_disaring_ke_cabang_aktif_dan_semua_cabang_menampilkan_semuanya(): void
    {
        $diUtama = $this->jual($this->pemilik, $this->teh);
        $this->pilihCabang($this->pemilik, $this->jabong);
        $diJabong = $this->jual($this->pemilik, $this->ayam);

        $this->get(route('sales.index'))->assertOk()->assertSee($diJabong->number)->assertDontSee($diUtama->number);
        $this->get(route('sales.show', $diUtama))->assertNotFound();

        $this->pilihCabang($this->pemilik, 'semua');
        $this->get(route('sales.index'))->assertOk()->assertSee($diJabong->number)->assertSee($diUtama->number)
            ->assertSee('Cabang aktif: Semua cabang');

        $this->pilihCabang($this->pemilik, $this->utama);
        $this->get(route('sales.index'))->assertSee($diUtama->number)->assertDontSee($diJabong->number);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_pengguna_dengan_akses_satu_cabang_hanya_melihat_dan_memilih_cabangnya(): void
    {
        $diUtama = $this->jual($this->pemilik, $this->teh);
        $kasir = $this->buatPengguna(RoleName::Kasir, ['all_branches' => false]);
        $kasir->branches()->sync([$this->jabong->id]);

        $this->actingAs($kasir)->get(route('profile.edit'))->assertOk()->assertSee('Cabang aktif: Cabang Jabong');
        $this->get(route('pos.index'))->assertOk()->assertSee('Gudang Jabong');
        $milikKasir = $this->jual($kasir, $this->ayam);
        $this->assertSame($this->jabong->id, $milikKasir->branch_id);

        $this->put(route('current-branch.update'), ['branch_id' => $this->utama->id])->assertSessionHasErrors('branch_id');
        $this->put(route('current-branch.update'), ['branch_id' => 'semua'])->assertSessionHasErrors('branch_id');
        $this->assertSame([$this->jabong->id], app(CurrentBranch::class)->scopeIds());
        $this->assertSame(0, Sale::query()->whereKey($diUtama->id)->count());
    }

    public function test_dokumen_yang_jatuh_ke_cabang_di_luar_akses_ditolak(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin, ['all_branches' => false]);
        $admin->branches()->sync([$this->utama->id]);

        $this->actingAs($admin)->post(route('purchases.store'), [
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->gudangJabong->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $this->teh->id, 'quantity' => '1', 'unit_price' => '3000', 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0',
            'paid_amount' => '3000', 'payment_method' => 'cash', 'update_cost' => '0',
        ])->assertSessionHas('error', 'Dokumen ini masuk ke Cabang Jabong, di luar cabang aktif Anda (Cabang Utama). Ganti cabang aktif di topbar, atau pilih gudang cabang ini.');

        $this->assertSame(0, Purchase::query()->withoutGlobalScope(BranchScope::class)->count());
        $this->assertSame(20.0, (float) $this->teh->fresh()->stock);
    }

    public function test_akses_cabang_diatur_dari_formulir_pengguna(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);
        $data = ['name' => $kasir->name, 'email' => $kasir->email, 'role' => RoleName::Kasir->value, 'is_active' => '1'];

        $this->actingAs($this->pemilik)->get(route('users.edit', $kasir))->assertOk()->assertSee('Akses cabang');

        $this->put(route('users.update', $kasir), $data + ['all_branches' => '0'])->assertSessionHasErrors('branches');

        $this->put(route('users.update', $kasir), $data + ['all_branches' => '0', 'branches' => [$this->jabong->id]])->assertSessionHasNoErrors();
        $kasir->refresh();
        $this->assertFalse($kasir->all_branches);
        $this->assertSame([$this->jabong->id], $kasir->branches()->pluck('branches.id')->all());
        $this->assertTrue(Activity::where('event', 'branch_access_changed')->where('properties->attributes->akses_cabang', 'Cabang Jabong')->exists());

        $this->put(route('users.update', $kasir), $data + ['all_branches' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($kasir->fresh()->all_branches);
        $this->assertSame(0, $kasir->branches()->count());
    }

    public function test_satu_cabang_saja_tidak_menyaring_data(): void
    {
        $this->jabong->delete();
        $this->jabong->forceDelete();
        $this->actingAs($this->pemilik)->get(route('dashboard'))->assertOk();

        $this->assertNull(app(CurrentBranch::class)->scopeIds());
    }
}
