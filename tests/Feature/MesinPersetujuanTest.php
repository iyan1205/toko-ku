<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\Journal;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalRequested;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Approval\ApprovalService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/**
 * Mesin persetujuan: pencocokan alur (kondisi, cabang, prioritas), tahap berurutan, tolak, tarik,
 * larangan menyetujui sendiri, notifikasi, serta pemakaiannya di purchase order dan jurnal manual.
 */
class MesinPersetujuanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $purchasing;

    private User $admin;

    private User $pemilik;

    private User $kasir;

    private Supplier $supplier;

    private Product $ayam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->purchasing = $this->buatPengguna(RoleName::Purchasing, ['name' => 'Rina Purchasing']);
        $this->admin = $this->buatPengguna(RoleName::Admin, ['name' => 'Agus Admin']);
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik, ['name' => 'Bu Pemilik']);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->supplier = Supplier::factory()->create(['name' => 'PT Sumber']);
        $this->ayam = Product::factory()->create(['name' => 'Ayam', 'purchase_price' => 500000]);
    }

    /** PO > Rp 5.000.000: tahap 1 peran Admin, tahap 2 pengguna Pemilik. */
    private function alurPoBesar(array $ubah = []): ApprovalFlow
    {
        $flow = ApprovalFlow::create(array_merge([
            'name' => 'PO besar',
            'document_type' => 'purchase_order',
            'conditions' => [['field' => 'total', 'operator' => '>', 'value' => '5000000.00']],
            'priority' => 100,
            'is_active' => true,
        ], $ubah));
        $flow->steps()->create(['sequence' => 1, 'name' => 'Kepala toko', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Admin->value)->id]);
        $flow->steps()->create(['sequence' => 2, 'name' => 'Pemilik', 'approver_type' => 'user', 'user_id' => $this->pemilik->id]);

        return $flow;
    }

    /** @return array<string, mixed> 10 x harga = total PO */
    private function payloadPo(int $harga, string $aksi = 'draft'): array
    {
        return [
            'aksi' => $aksi,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::default()->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->ayam->id, 'quantity' => '10', 'unit_price' => (string) $harga, 'discount' => '0']],
            'discount' => '0', 'tax' => '0', 'other_cost' => '0',
        ];
    }

    private function ajukanPoBesar(User $oleh): PurchaseOrder
    {
        $this->actingAs($oleh)->post(route('purchase-orders.store'), $this->payloadPo(600000, 'submit'))->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    public function test_po_di_atas_batas_diajukan_lalu_disetujui_berurutan_per_tahap(): void
    {
        Notification::fake();
        $this->alurPoBesar();

        $po = $this->ajukanPoBesar($this->purchasing);
        $this->assertSame(PurchaseOrderStatus::Submitted, $po->status);
        $pengajuan = ApprovalRequest::sole();
        $this->assertSame(1, $pengajuan->current_step);
        $this->assertSame('6000000.00', $pengajuan->amount);
        Notification::assertSentTo($this->admin, ApprovalRequested::class);
        Notification::assertNotSentTo([$this->pemilik, $this->kasir], ApprovalRequested::class);

        // Bukan penyetuju tahap 1.
        $this->actingAs($this->kasir)->post(route('approvals.approve', $pengajuan))->assertSessionHas('error', 'Anda bukan penyetuju tahap ini.');
        $this->assertSame(1, $pengajuan->fresh()->current_step);

        // Tahap 1: Admin (dari kotak masuk).
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()->assertSee($po->number)->assertSee('Tahap 1 dari 2');
        $this->post(route('approvals.approve', $pengajuan), ['catatan' => 'Harga sudah dicek'])->assertSessionHas('success');
        $pengajuan->refresh();
        $this->assertSame(2, $pengajuan->current_step);
        $this->assertSame($this->pemilik->id, $pengajuan->current_user_id);
        $this->assertSame(PurchaseOrderStatus::Submitted, $po->fresh()->status);
        Notification::assertSentTo($this->pemilik, ApprovalRequested::class);

        // Admin tidak bisa menyetujui tahap 2.
        $this->post(route('approvals.approve', $pengajuan))->assertSessionHas('error');

        // Tahap 2: Pemilik → PO disetujui atas namanya.
        $this->actingAs($this->pemilik)->post(route('approvals.approve', $pengajuan))->assertSessionHas('success');
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Approved, $po->status);
        $this->assertSame($this->pemilik->id, $po->approved_by);
        $this->assertTrue($po->status->canReceive());
        $this->assertSame(ApprovalStatus::Approved, $pengajuan->fresh()->status);
        $this->assertSame(
            [ApprovalAction::Submitted, ApprovalAction::Approved, ApprovalAction::Approved],
            $pengajuan->history()->get()->pluck('action')->all(),
        );
        $this->assertSame('Harga sudah dicek', $pengajuan->history()->where('user_id', $this->admin->id)->value('note'));
        Notification::assertSentTo($this->purchasing, ApprovalDecided::class, fn (ApprovalDecided $n) => $n->request->is($pengajuan));

        $this->actingAs($this->purchasing)->get(route('purchase-orders.show', $po))->assertOk()->assertSee('Persetujuan')->assertSee('Harga sudah dicek');
    }

    public function test_po_di_bawah_batas_tetap_disetujui_langsung_tetapi_po_besar_tidak_bisa_dilewati(): void
    {
        $this->alurPoBesar();

        $this->actingAs($this->admin)->post(route('purchase-orders.store'), $this->payloadPo(400000, 'approve'))->assertSessionHasNoErrors();
        $kecil = PurchaseOrder::latest('id')->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::Approved, $kecil->status);
        $this->assertSame(0, ApprovalRequest::count());

        // "Simpan & setujui" untuk PO besar otomatis menjadi pengajuan.
        $this->post(route('purchase-orders.store'), $this->payloadPo(600000, 'approve'))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Submitted, PurchaseOrder::latest('id')->first()->status);

        // Tombol Setujui langsung pada draft PO besar ditolak.
        $this->post(route('purchase-orders.store'), $this->payloadPo(600000));
        $draft = PurchaseOrder::latest('id')->firstOrFail();
        $this->post(route('purchase-orders.approve', $draft))->assertSessionHas('error', "Purchase order {$draft->number} perlu persetujuan berjenjang (PO besar). Ajukan persetujuan.");
        $this->assertSame(PurchaseOrderStatus::Draft, $draft->fresh()->status);
    }

    public function test_ditolak_kembali_ke_draft_dengan_alasan_lalu_bisa_diajukan_ulang(): void
    {
        Notification::fake();
        $this->alurPoBesar();
        $po = $this->ajukanPoBesar($this->purchasing);
        $pengajuan = ApprovalRequest::sole();

        $this->actingAs($this->admin)->post(route('approvals.reject', $pengajuan), ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->post(route('approvals.reject', $pengajuan), ['alasan' => 'Harga terlalu mahal'])->assertSessionHas('success');

        $this->assertSame(ApprovalStatus::Rejected, $pengajuan->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Draft, $po->fresh()->status);
        Notification::assertSentTo($this->purchasing, ApprovalDecided::class, fn (ApprovalDecided $n) => $n->note === 'Harga terlalu mahal');

        $this->actingAs($this->purchasing)->get(route('purchase-orders.edit', $po))->assertOk();
        $this->post(route('purchase-orders.submit', $po))->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::Submitted, $po->fresh()->status);
        $this->assertSame(2, $po->approvalRequests()->count());
        $this->assertSame(1, ApprovalRequest::pending()->count());
    }

    public function test_pengaju_tidak_bisa_menyetujui_sendiri_kecuali_alurnya_mengizinkan(): void
    {
        $flow = ApprovalFlow::create(['name' => 'Semua PO', 'document_type' => 'purchase_order', 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Admin', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Admin->value)->id]);

        $po = $this->ajukanPoBesar($this->admin);
        $pengajuan = ApprovalRequest::sole();

        $this->post(route('approvals.approve', $pengajuan))->assertSessionHas('error', 'Anda yang mengajukan dokumen ini, jadi persetujuannya harus oleh pengguna lain.');
        $this->assertSame(0, app(ApprovalService::class)->inbox($this->admin)->count());
        $this->post(route('approvals.cancel', $pengajuan))->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::Draft, $po->fresh()->status);

        $flow->update(['allow_self_approval' => true]);
        $this->post(route('purchase-orders.submit', $po))->assertSessionHas('success');
        $baru = ApprovalRequest::pending()->sole();
        $this->assertSame(1, app(ApprovalService::class)->inbox($this->admin)->count());
        $this->post(route('approvals.approve', $baru))->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::Approved, $po->fresh()->status);
    }

    public function test_alur_dipilih_berdasarkan_cabang_dan_prioritas(): void
    {
        $jabong = Branch::query()->create(['code' => 'JBG', 'name' => 'Cabang Jabong', 'is_active' => true]);
        $this->alurPoBesar(['name' => 'PO besar Jabong', 'branch_id' => $jabong->id]);

        $po = app(PurchaseOrderService::class)->create($this->payloadPo(600000), $this->admin);
        $layanan = app(ApprovalService::class);
        $this->assertNull($layanan->flowFor($po), 'Alur cabang Jabong tidak berlaku untuk PO cabang utama.');

        $umum = $this->alurPoBesar(['name' => 'Umum prioritas 50', 'priority' => 50]);
        $this->alurPoBesar(['name' => 'Umum prioritas 90', 'priority' => 90]);
        $this->assertTrue($layanan->flowFor($po)->is($umum));

        $po->forceFill(['branch_id' => $jabong->id])->save();
        $this->assertSame('Umum prioritas 50', $layanan->flowFor($po)->name);
        ApprovalFlow::where('name', 'Umum prioritas 90')->delete();
        $umum->update(['priority' => 100]);
        $this->assertSame('PO besar Jabong', $layanan->flowFor($po->fresh())->name, 'Prioritas sama: alur khusus cabang didahulukan.');
    }

    public function test_pembatalan_po_ikut_menarik_pengajuan_dan_perubahan_alur_tidak_mengubah_pengajuan_berjalan(): void
    {
        $flow = $this->alurPoBesar();
        $po = $this->ajukanPoBesar($this->purchasing);

        // Ubah alur: pengajuan yang berjalan tetap memakai tahap lama (snapshot).
        $flow->steps()->delete();
        $flow->steps()->create(['sequence' => 1, 'name' => 'Hanya kasir', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Kasir->value)->id]);
        $pengajuan = ApprovalRequest::sole();
        $this->assertSame('Kepala toko', $pengajuan->currentStep()['name']);
        $this->assertTrue(app(ApprovalService::class)->canAct($pengajuan, $this->admin));

        $this->actingAs($this->admin)->post(route('purchase-orders.cancel', $po), ['alasan' => 'Supplier tutup'])->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
        $this->assertSame(ApprovalStatus::Cancelled, $pengajuan->fresh()->status);
        $this->assertSame(0, app(ApprovalService::class)->inbox($this->admin)->count());
    }

    public function test_alur_dengan_penyetuju_yang_sudah_tidak_ada_menolak_pengajuan(): void
    {
        $flow = ApprovalFlow::create(['name' => 'Rusak', 'document_type' => 'purchase_order', 'priority' => 1, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Manajer', 'approver_type' => 'user', 'user_id' => $this->kasir->id]);
        $this->kasir->delete();

        $po = app(PurchaseOrderService::class)->create($this->payloadPo(100000), $this->purchasing);

        $this->expectException(AturanBisnisException::class);
        $this->expectExceptionMessage('Alur persetujuan "Rusak" tahap 1 (Manajer) belum punya penyetuju.');
        app(PurchaseOrderService::class)->submit($po, $this->purchasing);
    }

    public function test_jurnal_manual_besar_diajukan_dan_diposting_otomatis_setelah_disetujui(): void
    {
        app(ChartOfAccountsService::class)->install();
        $akuntan = $this->buatPengguna(RoleName::Akuntan);
        $flow = ApprovalFlow::create([
            'name' => 'Jurnal besar', 'document_type' => 'journal', 'priority' => 100, 'is_active' => true,
            'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => '10000000.00']],
        ]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Pemilik', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Pemilik->value)->id]);

        $jurnal = fn (string $nilai) => [
            'journal_date' => now()->toDateString(),
            'description' => 'Setoran modal',
            'lines' => [
                ['account_id' => Account::where('code', '1-1100')->value('id'), 'debit' => $nilai, 'credit' => ''],
                ['account_id' => Account::where('code', '3-1000')->value('id'), 'debit' => '', 'credit' => $nilai],
            ],
        ];

        $this->actingAs($akuntan)->post(route('journals.store'), $jurnal('1000000'))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Posted, Journal::latest('id')->first()->documentStatus());

        $this->post(route('journals.store'), $jurnal('15000000'))->assertSessionHas('success');
        $besar = Journal::latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::Submitted, $besar->documentStatus());
        $this->get(route('accounts.index'))->assertOk()->assertDontSee('16.000.000');

        $this->actingAs($this->pemilik)->post(route('approvals.approve', ApprovalRequest::sole()))->assertSessionHas('success');
        $besar->refresh();
        $this->assertSame(DocumentStatus::Posted, $besar->documentStatus());
        $this->assertSame($this->pemilik->id, $besar->posted_by);
        $this->actingAs($akuntan)->get(route('accounts.index'))->assertSee('16.000.000');
    }

    public function test_pengaturan_alur_persetujuan(): void
    {
        $adminRole = Role::findByName(RoleName::Admin->value);
        $data = [
            'name' => 'PO besar',
            'document_type' => 'purchase_order',
            'priority' => '100',
            'is_active' => '1',
            'conditions' => [['field' => 'total', 'operator' => '>', 'value' => '5000000']],
            'steps' => [
                ['name' => 'Kepala toko', 'approver_type' => 'role', 'role_id' => (string) $adminRole->id],
                ['name' => 'Pemilik', 'approver_type' => 'user', 'user_id' => (string) $this->pemilik->id],
            ],
        ];

        $this->actingAs($this->kasir)->get(route('settings.approval-flows.index'))->assertForbidden();

        $this->actingAs($this->pemilik)->get(route('settings.approval-flows.create'))->assertOk()->assertSee('Tahap persetujuan');
        $this->post(route('settings.approval-flows.store'), array_merge($data, ['steps' => []]))->assertSessionHasErrors('steps');
        $this->post(route('settings.approval-flows.store'), array_merge($data, ['steps' => [['name' => 'Tanpa peran', 'approver_type' => 'role', 'role_id' => '']]]))
            ->assertSessionHasErrors('steps.0.role_id');

        $this->post(route('settings.approval-flows.store'), $data)->assertRedirect(route('settings.approval-flows.index'));
        $flow = ApprovalFlow::sole();
        $this->assertSame([['field' => 'total', 'operator' => '>', 'value' => '5000000.00']], $flow->conditions);
        $this->assertSame([[1, 'Kepala toko', $adminRole->id, null], [2, 'Pemilik', null, $this->pemilik->id]],
            $flow->steps->map(fn ($s) => [$s->sequence, $s->name, $s->role_id, $s->user_id])->all());

        $this->get(route('settings.approval-flows.index'))->assertOk()->assertSee('Total lebih dari Rp 5.000.000')->assertSee('Peran Admin');

        $this->put(route('settings.approval-flows.update', $flow), array_merge($data, ['steps' => [$data['steps'][1]]]))->assertSessionHasNoErrors();
        $this->assertSame(['Pemilik'], $flow->fresh()->steps->pluck('name')->all());

        $this->delete(route('settings.approval-flows.destroy', $flow))->assertSessionHas('success');
        $this->assertSoftDeleted($flow);
    }
}
