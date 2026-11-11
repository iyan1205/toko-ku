<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\ApprovalFlow;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\Journal;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Kas & bank: rekening, penerimaan, pengeluaran (dengan persetujuan), transfer, kas kecil, pembatalan. */
class KasBankTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    private CashBankAccount $kas;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
        $this->kas = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1-1100'))->sole();
        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1-1200'))->sole();
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, string> kode => "D 5000000.00" */
    private function baris(Journal $journal): array
    {
        $hasil = [];
        foreach ($journal->lines()->with('account')->get() as $line) {
            $sisi = (float) $line->debit > 0 ? 'D' : 'K';
            $hasil[$line->account->code] = $sisi.' '.Uang::keDesimal(Uang::sen($sisi === 'D' ? $line->debit : $line->credit));
        }
        ksort($hasil);

        return $hasil;
    }

    private function catat(string $jenis, array $data): CashTransaction
    {
        $this->actingAs($this->akuntan)->post(route('cash-transactions.store', $jenis), array_merge([
            'aksi' => 'post', 'transaction_date' => now()->format('Y-m-d'), 'cash_bank_account_id' => $this->kas->id,
        ], $data))->assertSessionHasNoErrors();

        return CashTransaction::latest('id')->firstOrFail();
    }

    public function test_coa_standar_langsung_punya_rekening_kas_dan_bank(): void
    {
        $this->assertSame(['cash', 'bank'], CashBankAccount::orderBy('id')->pluck('kind')->map->value->all());

        $this->actingAs($this->akuntan)->get(route('cash-bank-accounts.index'))->assertOk()->assertSee('Kas')->assertSee('Bank');
    }

    public function test_penerimaan_dan_pengeluaran_membuat_jurnal_dengan_akun_lawan_per_baris(): void
    {
        $masuk = $this->catat('masuk', ['description' => 'Setoran modal', 'counterparty' => 'Pemilik', 'lines' => [['account_id' => $this->akun('3-1000'), 'amount' => '5000000']]]);
        $keluar = $this->catat('keluar', ['description' => 'Listrik dan ATK', 'lines' => [
            ['account_id' => $this->akun('6-1200'), 'amount' => '350000'],
            ['account_id' => $this->akun('6-1300'), 'amount' => '50000', 'description' => 'Kertas struk'],
        ]]);

        $this->assertSame(DocumentStatus::Posted, $masuk->documentStatus());
        $this->assertSame('BKM-'.now()->format('Ymd').'-0001', $masuk->number);
        $this->assertSame(['1-1100' => 'D 5000000.00', '3-1000' => 'K 5000000.00'], $this->baris($masuk->journals()->sole()));
        $this->assertSame(['1-1100' => 'K 400000.00', '6-1200' => 'D 350000.00', '6-1300' => 'D 50000.00'], $this->baris($keluar->journals()->sole()));
        $this->assertSame('400000.00', (string) $keluar->amount);

        $this->get(route('cash-bank-accounts.index'))->assertSee('Rp 4.600.000');
        $this->get(route('cash-transactions.show', $keluar))->assertOk()->assertSee('Kertas struk')->assertSee($keluar->journals()->sole()->number);
    }

    public function test_akun_lawan_tidak_boleh_akun_rekening_itu_sendiri(): void
    {
        $this->actingAs($this->akuntan)->post(route('cash-transactions.store', 'keluar'), [
            'aksi' => 'post', 'transaction_date' => now()->format('Y-m-d'), 'cash_bank_account_id' => $this->kas->id, 'description' => 'Salah',
            'lines' => [['account_id' => $this->akun('1-1100'), 'amount' => '1000']],
        ])->assertSessionHasErrors('lines.0.account_id');

        $this->assertSame(0, CashTransaction::count());
    }

    public function test_transfer_dengan_biaya_admin_dan_pengisian_ulang_kas_kecil(): void
    {
        $this->catat('masuk', ['description' => 'Modal', 'lines' => [['account_id' => $this->akun('3-1000'), 'amount' => '2000000']]]);
        $this->post(route('cash-bank-accounts.store'), ['kind' => 'petty_cash', 'name' => 'Kas Kecil Dapur', 'imprest_amount' => '500000'])->assertSessionHasNoErrors();
        $kecil = CashBankAccount::where('kind', 'petty_cash')->sole();
        $this->assertSame('1-1101', $kecil->account->code, 'Akun kas baru dibuat otomatis di bawah Aset Lancar.');
        $this->assertTrue($kecil->account->is_cash_bank);

        $transfer = $this->catat('transfer', ['target_cash_bank_account_id' => $this->bank->id, 'description' => 'Setor ke bank', 'amount' => '1000000', 'fee' => '6500']);
        $this->assertSame(['1-1100' => 'K 1006500.00', '1-1200' => 'D 1000000.00', '6-1600' => 'D 6500.00'], $this->baris($transfer->journals()->sole()));

        $this->catat('transfer', ['target_cash_bank_account_id' => $kecil->id, 'description' => 'Isi kas kecil', 'amount' => '200000']);
        $this->get(route('cash-transactions.create', ['jenis' => 'transfer', 'tujuan' => $kecil->id]))->assertOk()
            ->assertSee('value="300000.00"', false)->assertSee('Pengisian ulang Kas Kecil Dapur');
    }

    public function test_pengeluaran_besar_menunggu_persetujuan_lalu_diposting_saat_disetujui(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $flow = ApprovalFlow::create(['name' => 'Kas keluar besar', 'document_type' => 'cash_out', 'conditions' => [['field' => 'total', 'operator' => '>', 'value' => '1000000.00']], 'priority' => 100, 'is_active' => true]);
        $flow->steps()->create(['sequence' => 1, 'name' => 'Pemilik', 'approver_type' => 'role', 'role_id' => Role::findByName(RoleName::Pemilik->value)->id]);

        $keluar = $this->catat('keluar', ['description' => 'Bayar sewa', 'lines' => [['account_id' => $this->akun('6-1100'), 'amount' => '3000000']]]);
        $this->assertSame(DocumentStatus::Submitted, $keluar->documentStatus());
        $this->assertSame(0, $keluar->journals()->count(), 'Belum mengubah saldo sebelum disetujui.');

        $this->actingAs($pemilik)->post(route('approvals.approve', $keluar->pendingApproval()))->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Posted, $keluar->fresh()->documentStatus());
        $this->assertSame(['1-1100' => 'K 3000000.00', '6-1100' => 'D 3000000.00'], $this->baris($keluar->journals()->sole()));
    }

    public function test_transaksi_posted_dibatalkan_dengan_jurnal_pembalik(): void
    {
        $masuk = $this->catat('masuk', ['description' => 'Pendapatan sewa lapak', 'lines' => [['account_id' => $this->akun('4-2000'), 'amount' => '250000']]]);

        $this->post(route('cash-transactions.cancel', $masuk), ['alasan' => 'Salah rekening'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Cancelled, $masuk->fresh()->documentStatus());
        $pembalik = Journal::whereNotNull('reversal_of_id')->sole();
        $this->assertSame(['1-1100' => 'K 250000.00', '4-2000' => 'D 250000.00'], $this->baris($pembalik));
    }

    public function test_tanpa_izin_kas_bank_ditolak(): void
    {
        $kasir = $this->buatPengguna(RoleName::Kasir);

        $this->actingAs($kasir)->get(route('cash-bank-accounts.index'))->assertForbidden();
        $this->actingAs($kasir)->post(route('cash-transactions.store', 'masuk'), [])->assertForbidden();
    }
}
