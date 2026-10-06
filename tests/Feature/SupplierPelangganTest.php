<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class SupplierPelangganTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
    }

    // ---------------- Supplier ----------------

    public function test_supplier_dibuat_dengan_kode_otomatis_berurutan(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('suppliers.store'), ['name' => 'PT Sumber Rejeki', 'phone' => '021-555-1234', 'npwp' => '01.234.567.8-901.000'])
            ->assertSessionHasNoErrors();
        $this->post(route('suppliers.store'), ['name' => 'CV Maju Jaya'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['SUP-0001', 'SUP-0002'],
            Supplier::orderBy('id')->pluck('code')->all()
        );
    }

    public function test_kode_supplier_tidak_dipakai_ulang_setelah_dihapus(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('suppliers.store'), ['name' => 'A']);
        Supplier::firstOrFail()->delete();
        $this->post(route('suppliers.store'), ['name' => 'B']);

        $this->assertSame('SUP-0002', Supplier::where('name', 'B')->value('code'));
    }

    public function test_validasi_supplier(): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => '', 'phone' => 'abc', 'npwp' => 'xyz', 'email' => 'bukan-email'])
            ->assertSessionHasErrors(['name', 'phone', 'npwp', 'email']);

        $this->assertSame(0, Supplier::count());
    }

    public function test_supplier_dapat_diubah_tanpa_mengganti_kode_dan_dihapus_secara_soft_delete(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'SUP-0007', 'name' => 'Lama']);

        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), ['name' => 'Baru', 'code' => 'HACK-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('SUP-0007', $supplier->fresh()->code);
        $this->assertSame('Baru', $supplier->fresh()->name);

        $this->delete(route('suppliers.destroy', $supplier))->assertRedirect(route('suppliers.index'));
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    // ---------------- Pelanggan ----------------

    public function test_pelanggan_umum_dibuat_sekali_dan_ditandai_bawaan(): void
    {
        $a = Customer::umum();
        $b = Customer::umum();

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Customer::where('code', 'UMUM')->count());
        $this->assertTrue($a->is_default);
        $this->assertSame('Umum', $a->name);
    }

    public function test_pelanggan_dibuat_dengan_kode_otomatis_dan_saldo_piutang_nol(): void
    {
        Customer::umum();
        $this->actingAs($this->admin);

        $this->post(route('customers.store'), ['name' => 'Budi', 'phone' => '0812-3456-7890', 'receivable_balance' => 500000])
            ->assertSessionHasNoErrors();
        $this->post(route('customers.store'), ['name' => 'Siti']);

        $this->assertSame(['CUS-0001', 'CUS-0002'], Customer::where('is_default', false)->orderBy('id')->pluck('code')->all());
        $this->assertSame(0.0, (float) Customer::where('name', 'Budi')->value('receivable_balance'));
    }

    public function test_pelanggan_umum_tidak_dapat_diubah_atau_dihapus(): void
    {
        $umum = Customer::umum();

        $this->actingAs($this->admin)
            ->put(route('customers.update', $umum), ['name' => 'Diganti'])
            ->assertSessionHas('error');
        $this->delete(route('customers.destroy', $umum))->assertSessionHas('error');

        $this->assertSame('Umum', $umum->fresh()->name);
        $this->assertNotSoftDeleted('customers', ['id' => $umum->id]);
    }

    public function test_pelanggan_dengan_piutang_tidak_dapat_dihapus(): void
    {
        $berutang = Customer::factory()->piutang(250000)->create();
        $lunas = Customer::factory()->create();

        $this->actingAs($this->admin);

        $this->delete(route('customers.destroy', $berutang))->assertSessionHas('error');
        $this->assertNotSoftDeleted('customers', ['id' => $berutang->id]);

        $this->delete(route('customers.destroy', $lunas))->assertSessionHas('success');
        $this->assertSoftDeleted('customers', ['id' => $lunas->id]);
    }

    public function test_pelanggan_umum_tampil_paling_atas_dan_dapat_dicari(): void
    {
        Customer::factory()->create(['name' => 'Aaa Pelanggan']);
        Customer::umum();

        $this->actingAs($this->admin)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSeeInOrder(['Umum', 'Aaa Pelanggan']);

        $this->get(route('customers.index', ['cari' => 'Aaa']))
            ->assertSee('Aaa Pelanggan')
            ->assertDontSee('Bawaan sistem');
    }

    // ---------------- Hak akses ----------------

    public function test_kasir_tidak_dapat_mengakses_supplier_dan_pelanggan(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir);
        $this->get(route('suppliers.index'))->assertForbidden();
        $this->post(route('suppliers.store'), ['name' => 'X'])->assertForbidden();
        $this->get(route('customers.index'))->assertForbidden();
        $this->post(route('customers.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_dapat_membuka_semua_halaman_master(): void
    {
        $this->actingAs($this->admin);

        foreach (['suppliers.index', 'suppliers.create', 'customers.index', 'customers.create', 'products.index', 'products.create', 'categories.index', 'units.index'] as $nama) {
            $this->get(route($nama))->assertOk();
        }
    }
}
