<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\ApprovalFlow;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\Code128;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Fase 16d: opname dengan scan & pembekuan stok, persetujuan penyesuaian, label barcode, impor produk. */
class OpnameLabelImporTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    private Product $teh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->teh = Product::factory()->create(['name' => 'Teh Botol', 'barcode' => '8990001', 'purchase_price' => 3000, 'selling_price' => 5000]);
        $dus = Unit::factory()->create(['name' => 'DUS']);
        $this->teh->units()->create(['unit_id' => $dus->id, 'factor' => 12, 'barcode' => 'DUS-TEH']);
        app(StockService::class)->catat($this->teh, StockMovementType::Purchase, 30, null, null, $this->admin, null, ['unit_cost' => 300000]);
    }

    private function mulai(bool $beku = true): StockOpname
    {
        $this->actingAs($this->admin)->post(route('stock-opnames.start'), [
            'warehouse_id' => Warehouse::default()->id, 'opname_date' => now()->toDateString(), 'freeze_stock' => $beku ? '1' : '0',
        ])->assertSessionHasNoErrors();

        return StockOpname::query()->latest('id')->firstOrFail();
    }

    public function test_opname_scan_dari_hp_dan_stok_gudang_dibekukan(): void
    {
        $opname = $this->mulai();
        $this->get(route('stock-opnames.count', $opname))->assertOk()->assertSee('Scan atau ketik barcode');

        $this->postJson(route('stock-opnames.scan', $opname), ['kode' => 'DUS-TEH'])->assertOk()->assertJson(['physical' => 12]);
        $this->postJson(route('stock-opnames.scan', $opname), ['kode' => '8990001', 'quantity' => 15])->assertOk()->assertJson(['physical' => 27, 'system' => 30, 'difference' => -3]);
        $this->postJson(route('stock-opnames.scan', $opname), ['kode' => 'TIDAK-ADA'])->assertNotFound();

        try {
            app(StockService::class)->catat($this->teh, StockMovementType::Sale, -1, null, null, $this->admin);
            $this->fail('Gudang beku harus menolak mutasi.');
        } catch (AturanBisnisException $e) {
            $this->assertStringContainsString('dibekukan untuk stok opname', $e->getMessage());
        }

        $this->post(route('stock-opnames.finalize', $opname))->assertSessionHas('error');
        $opname->items()->update(['reason' => 'Botol pecah']);
        $this->post(route('stock-opnames.finalize', $opname))->assertSessionHas('success');

        $this->assertSame(StockOpnameStatus::Final, $opname->fresh()->status);
        $this->assertSame(27.0, (float) $this->teh->fresh()->stock);
        app(StockService::class)->catat($this->teh, StockMovementType::Sale, -1, null, null, $this->admin); // beku dilepas
    }

    public function test_opname_dengan_selisih_besar_melewati_alur_persetujuan(): void
    {
        $flow = ApprovalFlow::create(['name' => 'Opname', 'document_type' => 'stock_opname', 'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => '10000']], 'priority' => 1, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Pemilik', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Pemilik->value)->id]);
        $this->assertSame('Nilai selisih minimal Rp 10.000', $flow->conditionSummary());

        $opname = $this->mulai(beku: false);
        $this->postJson(route('stock-opnames.scan', $opname), ['kode' => '8990001', 'quantity' => 20])->assertOk(); // selisih -10 × 3.000 = 30.000
        $opname->items()->update(['reason' => 'Hilang']);

        $this->post(route('stock-opnames.finalize', $opname))->assertSessionHas('success', fn ($p) => str_contains($p, 'diajukan untuk persetujuan'));
        $this->assertSame(StockOpnameStatus::Submitted, $opname->fresh()->status);
        $this->assertSame(30.0, (float) $this->teh->fresh()->stock);

        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->post(route('approvals.approve', $opname->fresh()->pendingApproval()))->assertSessionHasNoErrors();
        $this->assertSame(StockOpnameStatus::Final, $opname->fresh()->status);
        $this->assertSame(20.0, (float) $this->teh->fresh()->stock);
    }

    public function test_cetak_label_barcode_code128(): void
    {
        $this->assertSame([104, 55, 73, 75, 73, 80, 69, 68, 73, 65, 88, 106], Code128::values('Wikipedia'));

        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->get(route('product-labels.index'))->assertOk()->assertSee('Teh Botol');
        $html = $this->get(route('product-labels.print', ['copies' => [$this->teh->id => 3], 'size' => '38x25', 'layout' => 'a4', 'show' => ['name', 'price']]))
            ->assertOk()->assertSee('Rp 5.000')->getContent();
        $this->assertSame(3, substr_count($html, 'aria-label="8990001"'));
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('product-labels.index'))->assertForbidden();
    }

    public function test_impor_produk_dengan_satuan_besar_lewat_pratinjau(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        Category::factory()->create(['name' => 'Minuman']);
        Unit::factory()->create(['name' => 'PCS']);
        $csv = "SKU,Nama Produk,Kategori,Satuan,Harga Beli,Harga Jual,Satuan Besar,Isi Satuan Besar,Harga Satuan Besar,Barcode Satuan Besar,Lacak Batch\n"
            .",Air Mineral,Minuman,PCS,2000,3000,DUS,24,65000,DUS-AIR,ya\n"
            ."{$this->teh->sku},Teh Botol Baru,Minuman,PCS,3100,5500,,,,,\n"
            .",Salah,Tidak Ada,PCS,1,2,,,,,\n";

        $this->actingAs($pemilik)->post(route('imports.products.preview'), ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)])->assertSessionHasNoErrors();
        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame([3, 1], [$batch->total_rows, $batch->invalid_rows]);
        $this->post(route('imports.products.commit', $batch))->assertSessionHas('success');

        $air = Product::query()->where('name', 'Air Mineral')->sole();
        $this->assertTrue($air->track_batches);
        $this->assertSame(['24.0000', 'DUS-AIR', '65000.00'], [$air->units->sole()->factor, $air->units->sole()->barcode, $air->units->sole()->selling_price]);
        $this->assertSame('Teh Botol Baru', $this->teh->fresh()->name);
        $this->assertSame(1, $this->teh->units()->count()); // satuan DUS lama tetap
    }
}
