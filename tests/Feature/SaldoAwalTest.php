<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\OpeningBalance;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\FiscalPeriodService;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Saldo awal: draf, posting menjadi satu jurnal seimbang (selisih ke Ekuitas Saldo Awal), pembatalan. */
class SaldoAwalTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $akuntan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->akuntan = $this->buatPengguna(RoleName::Akuntan);
        app(ChartOfAccountsService::class)->install();
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, mixed> */
    private function payload(array $ubah = []): array
    {
        return array_merge([
            'aksi' => 'draft',
            'balance_date' => now()->subDay()->format('Y-m-d'),
            'description' => 'Saldo per akhir bulan lalu',
            'accounts' => [
                ['account_id' => $this->akun('1-1100'), 'debit' => '2500000', 'credit' => ''],
                ['account_id' => $this->akun('1-1200'), 'debit' => '10000000', 'credit' => ''],
                ['account_id' => $this->akun('2-2100'), 'debit' => '', 'credit' => '5000000'],
            ],
            'inventory' => [['warehouse_id' => Warehouse::default()->id, 'amount' => '7000000']],
            'receivables' => [['customer_id' => Customer::factory()->create(['name' => 'Bu Siti'])->id, 'reference' => 'INV-LAMA-7', 'due_date' => now()->addDays(10)->format('Y-m-d'), 'amount' => '750000']],
            'payables' => [['supplier_id' => Supplier::factory()->create(['name' => 'CV Ayam'])->id, 'reference' => 'F-991', 'due_date' => '', 'amount' => '1250000']],
        ], $ubah);
    }

    /** @return array<string, string> */
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

    public function test_draf_lalu_posting_menjadi_satu_jurnal_seimbang_dengan_ekuitas_saldo_awal(): void
    {
        $this->actingAs($this->akuntan)->post(route('opening-balances.store'), $this->payload())->assertSessionHasNoErrors();

        $saldo = OpeningBalance::sole();
        $this->assertSame(DocumentStatus::Draft, $saldo->documentStatus());
        $this->assertCount(6, $saldo->lines);
        $this->assertSame(0, Journal::count(), 'Draf belum dijurnal.');
        $this->assertSame($this->akuntan->id, $saldo->created_by);

        $this->post(route('opening-balances.post', $saldo))->assertRedirect(route('opening-balances.show', $saldo));

        $jurnal = Journal::where('source_type', 'opening_balance')->sole();
        $this->assertSame(now()->subDay()->toDateString(), $jurnal->journal_date->toDateString());
        // Debit 2,5jt + 10jt + 7jt + 750rb = 20,25jt; kredit 5jt + 1,25jt = 6,25jt → selisih 14jt ke ekuitas.
        $this->assertSame([
            '1-1100' => 'D 2500000.00',
            '1-1200' => 'D 10000000.00',
            '1-1300' => 'D 750000.00',
            '1-1400' => 'D 7000000.00',
            '2-1100' => 'K 1250000.00',
            '2-2100' => 'K 5000000.00',
            '3-9000' => 'K 14000000.00',
        ], $this->baris($jurnal));
        $this->assertSame('20250000.00', (string) $saldo->fresh()->total);
        $this->assertSame(DocumentStatus::Posted, $saldo->fresh()->documentStatus());

        $this->get(route('opening-balances.show', $saldo))->assertOk()->assertSee('Bu Siti')->assertSee('INV-LAMA-7')->assertSee($jurnal->number);
        $this->get(route('journals.show', $jurnal))->assertOk()->assertSee(route('opening-balances.show', $saldo));
    }

    public function test_akun_persediaan_piutang_dan_hutang_harus_diisi_di_bagiannya(): void
    {
        $this->actingAs($this->akuntan)->post(route('opening-balances.store'), $this->payload([
            'accounts' => [
                ['account_id' => $this->akun('1-1300'), 'debit' => '100000', 'credit' => ''],
                ['account_id' => $this->akun('1-1100'), 'debit' => '100000', 'credit' => '100000'],
            ],
            'inventory' => [['warehouse_id' => Warehouse::default()->id, 'amount' => '0']],
        ]))->assertSessionHasErrors(['accounts.0.account_id', 'accounts.1.debit', 'inventory.0.amount']);

        $this->assertSame(0, OpeningBalance::count());
    }

    public function test_isi_dari_stok_saat_ini_memakai_jumlah_kali_harga_pokok(): void
    {
        Product::factory()->stok(4)->create(['purchase_price' => 2500]);
        Product::factory()->stok(2)->create(['purchase_price' => 1250.5]);

        $this->actingAs($this->akuntan)->get(route('opening-balances.create'))->assertOk()
            ->assertSee('Isi dari stok saat ini')
            ->assertSee('12501.00');
    }

    public function test_saldo_awal_posted_dibatalkan_dengan_jurnal_pembalik_di_tanggal_yang_sama(): void
    {
        $this->actingAs($this->akuntan)->post(route('opening-balances.store'), $this->payload(['aksi' => 'post']))->assertSessionHasNoErrors();
        $saldo = OpeningBalance::sole();

        $this->put(route('opening-balances.update', $saldo), $this->payload())->assertSessionHas('error');
        $this->post(route('opening-balances.cancel', $saldo), ['alasan' => 'Nilai bank salah'])->assertSessionHasNoErrors();

        $pembalik = Journal::whereNotNull('reversal_of_id')->sole();
        $this->assertSame('voided', $pembalik->source_event);
        $this->assertSame(now()->subDay()->toDateString(), $pembalik->journal_date->toDateString());
        $this->assertSame(DocumentStatus::Cancelled, $saldo->fresh()->documentStatus());
        $this->assertSame(0.0, (float) Journal::with('lines')->get()->flatMap->lines->where('account_id', $this->akun('3-9000'))->sum(fn ($l) => $l->credit - $l->debit));
    }

    public function test_periode_tertutup_dan_hak_akses(): void
    {
        app(FiscalPeriodService::class)->close(now()->subMonthNoOverflow()->year, now()->subMonthNoOverflow()->month, $this->buatPengguna(RoleName::Pemilik));

        $this->actingAs($this->akuntan)->post(route('opening-balances.store'), $this->payload(['balance_date' => now()->subMonthNoOverflow()->format('Y-m-d')]))
            ->assertSessionHasErrors('balance_date');

        $admin = $this->buatPengguna(RoleName::Admin);
        $this->actingAs($admin)->get(route('opening-balances.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('opening-balances.store'), $this->payload())->assertForbidden();
    }
}
