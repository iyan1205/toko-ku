<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Lampiran dokumen: unggah, pratinjau, unduh, hapus, izin per dokumen, dan saringan cabang. */
class LampiranDokumenTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $purchasing;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        Storage::fake('local');
        $this->purchasing = $this->buatPengguna(RoleName::Purchasing);
        $this->po = app(PurchaseOrderService::class)->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => Warehouse::default()->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => '1', 'unit_price' => '1000', 'discount' => '0']],
        ], $this->purchasing);
    }

    private function unggah(UploadedFile $file, ?User $oleh = null)
    {
        return $this->actingAs($oleh ?? $this->purchasing)
            ->postJson(route('attachments.store', ['purchase_order', $this->po->id]), ['file' => $file]);
    }

    public function test_unggah_lalu_pratinjau_unduh_dan_hapus(): void
    {
        $this->unggah(UploadedFile::fake()->image('faktur.jpg', 400, 300))
            ->assertCreated()
            ->assertJsonPath('item.name', 'faktur.jpg')
            ->assertJsonPath('item.kind', 'image');

        $lampiran = Attachment::sole();
        $this->assertSame('purchase_order', $lampiran->attachable_type);
        $this->assertSame($this->po->id, $lampiran->attachable_id);
        Storage::disk('local')->assertExists($lampiran->path);
        $this->assertStringStartsWith('attachments/purchase_order/', $lampiran->path);

        $this->get(route('attachments.show', $lampiran))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $this->get(route('attachments.show', $lampiran))->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $this->get(route('attachments.download', $lampiran))->headers->get('Content-Disposition'));

        $this->get(route('purchase-orders.show', $this->po))->assertOk()->assertSee('Lampiran')->assertSee('faktur.jpg');

        $this->delete(route('attachments.destroy', $lampiran))->assertRedirect();
        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($lampiran->path);
    }

    public function test_jenis_dan_ukuran_berkas_diperiksa(): void
    {
        $this->unggah(UploadedFile::fake()->create('skrip.php', 1, 'text/x-php'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->unggah(UploadedFile::fake()->create('besar.pdf', 20000, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->unggah(UploadedFile::fake()->create('nota.pdf', 50, 'application/pdf'))->assertCreated()->assertJsonPath('item.kind', 'pdf');
    }

    public function test_izin_mengikuti_dokumennya(): void
    {
        $this->unggah(UploadedFile::fake()->image('faktur.jpg'));
        $lampiran = Attachment::sole();

        $kasir = $this->buatPengguna(RoleName::Kasir);
        $this->actingAs($kasir)->get(route('attachments.show', $lampiran))->assertForbidden();
        $this->unggah(UploadedFile::fake()->image('lain.jpg'), $kasir)->assertForbidden();

        // Staf gudang boleh melihat PO tetapi tidak mengubahnya.
        $gudang = $this->buatPengguna(RoleName::StafGudang);
        $this->actingAs($gudang)->get(route('attachments.show', $lampiran))->assertOk();
        $this->actingAs($gudang)->delete(route('attachments.destroy', $lampiran))->assertForbidden();
        $this->assertSame(1, Attachment::count());
    }

    public function test_lampiran_dokumen_cabang_lain_tidak_bisa_dibuka(): void
    {
        $this->unggah(UploadedFile::fake()->image('faktur.jpg'));
        $lampiran = Attachment::sole();

        $jabong = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true]);
        $orangJabong = $this->buatPengguna(RoleName::Purchasing, ['all_branches' => false]);
        $orangJabong->branches()->sync([$jabong->id]);

        $this->actingAs($orangJabong)->get(route('attachments.show', $lampiran))->assertNotFound();
    }

    public function test_draft_yang_dihapus_ikut_menghapus_lampirannya(): void
    {
        $this->unggah(UploadedFile::fake()->image('faktur.jpg'));
        $path = Attachment::sole()->path;

        $this->actingAs($this->purchasing)->delete(route('purchase-orders.destroy', $this->po))->assertSessionHas('success');

        $this->assertSame(0, Attachment::count());
        Storage::disk('local')->assertMissing($path);
    }
}
