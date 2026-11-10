<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Daftar modul Kasir bisa diekspor ke Excel dan PDF. */
class EksporKasirTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    /** @return array<string, array{string}> */
    public static function daftarKasir(): array
    {
        return collect(['sales.export', 'customers.export', 'pos.sessions.export', 'sale-returns.export', 'receivables.export'])
            ->mapWithKeys(fn (string $route) => [$route => [$route]])->all();
    }

    #[DataProvider('daftarKasir')]
    public function test_daftar_bisa_diekspor_ke_excel_dan_pdf(string $route): void
    {
        $this->siapkanPeran();
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $ayam = Product::factory()->stok(10)->create(['selling_price' => 10000]);
        Customer::umum();
        $pelanggan = Customer::factory()->create();
        $this->actingAs($pemilik)->post(route('pos.sessions.store'), ['warehouse_id' => Warehouse::default()->id, 'opening_cash' => '100000'])->assertSessionHasNoErrors();
        $this->assertSame(1, CashSession::count());
        $this->postJson(route('sales.store'), [
            'customer_id' => $pelanggan->id,
            'items' => [['product_id' => $ayam->id, 'quantity' => '1', 'discount' => '0']],
            'payment_method' => 'receivable',
            'payments' => [['method' => 'cash', 'amount' => '4000']],
        ])->assertCreated();

        $this->get(route($route, ['format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get(route($route, ['format' => 'pdf']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
