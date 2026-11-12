<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Tax\TaxReportService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Pajak: tarif PPh bertanggal berlaku, PPh potong di tagihan pembelian, rekap PPN, ekspor faktur, bukti potong. */
class PajakPphPpnTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private Supplier $cv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        app(ChartOfAccountsService::class)->install();
        Customer::umum();
        $this->cv = Supplier::factory()->create(['name' => 'CV Teknik', 'npwp' => '01.234.567.8-901.000']);
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00'));
        $this->actingAs($this->pemilik);
    }

    private function akun(string $kode): int
    {
        return Account::where('code', $kode)->value('id');
    }

    /** @return array<string, string> */
    private function baris(Journal $journal): array
    {
        $hasil = [];
        foreach ($journal->lines()->with('account')->get() as $line) {
            $sisi = (float) $line->debit > 0 ? 'D' : 'K';
            $hasil[$line->account->code.' '.$sisi] = Uang::keDesimal(Uang::sen($sisi === 'D' ? $line->debit : $line->credit));
        }
        ksort($hasil);

        return $hasil;
    }

    private function tagihan(string $tanggal, ?string $pph = 'PPH23-JASA'): Invoice
    {
        $this->post(route('purchase-bills.store'), [
            'aksi' => 'post', 'supplier_id' => $this->cv->id, 'invoice_date' => $tanggal, 'tax_rate' => '11', 'withholding_code' => $pph, 'reference' => 'FP-0012',
            'lines' => [['description' => 'Servis mesin', 'account_id' => $this->akun('6-1900'), 'quantity' => '1', 'unit_price' => '1000000']],
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_pph23_dipotong_dari_tagihan_dan_dijurnal_ke_hutang_pph(): void
    {
        $tagihan = $this->tagihan('2026-11-20');

        $this->assertSame(['2.00', '1000000.00', '20000.00'], [(string) $tagihan->withholding_rate, (string) $tagihan->withholding_base, (string) $tagihan->withholding_amount]);
        $this->assertSame(['1110000.00', '1090000.00'], [(string) $tagihan->total, (string) $tagihan->balance], 'Yang dibayar ke supplier dikurangi PPh.');
        $this->assertSame([
            '1-1500 D' => '110000.00', '2-1100 K' => '1090000.00', '2-1410 K' => '20000.00', '6-1900 D' => '1000000.00',
        ], $this->baris($tagihan->journals()->sole()));
    }

    public function test_tarif_yang_dipakai_mengikuti_tanggal_berlaku(): void
    {
        $this->post(route('settings.tax-rates.store'), ['kind' => 'pph23', 'code' => 'pph23-jasa', 'name' => 'PPh 23 jasa', 'rate' => '3', 'effective_from' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->post(route('settings.tax-rates.store'), ['kind' => 'pph23', 'code' => 'PPH23-JASA', 'name' => 'Dobel', 'rate' => '4', 'effective_from' => '2026-12-01'])->assertSessionHasErrors('effective_from');

        $this->assertSame('2.00', (string) $this->tagihan('2026-11-30')->withholding_rate);
        $this->assertSame('3.00', (string) $this->tagihan('2026-12-02')->withholding_rate);
        $this->assertSame(2, TaxRate::where('code', 'PPH23-JASA')->count());
    }

    public function test_rekap_ppn_masa_dari_jurnal_dan_ekspor_faktur_mengikuti_config(): void
    {
        setting(['tax.enabled' => true, 'tax.name' => 'PPN', 'tax.rate' => '11', 'tax.inclusive' => false]);
        $pembeli = Customer::factory()->create(['name' => 'PT Pembeli', 'tax_id' => '02.111.222.3-444.000']);
        $ayam = Product::factory()->stok(10)->create(['selling_price' => 100000, 'purchase_price' => 50000]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00'));
        $this->postJson(route('sales.store'), ['customer_id' => $pembeli->id, 'items' => [['product_id' => $ayam->id, 'quantity' => '2', 'discount' => '0']], 'discount' => '0',
            'payments' => [['method' => 'cash', 'amount' => '222000']]])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00'));
        $this->tagihan('2026-11-12', null);

        $november = app(TaxReportService::class)->ppnRecap(2026)[10];
        $this->assertSame([2200000, 11000000, -8800000], [$november['output'], $november['input'], $november['net']], 'Keluaran 22.000, masukan 110.000: lebih bayar.');

        $keluaran = app(TaxReportService::class)->documents('2026-11', 'keluaran');
        $this->assertSame([['PT Pembeli', '02.111.222.3-444.000', 20000000, 2200000]], $keluaran->map(fn ($d) => [$d['party_name'], $d['party_tax_id'], $d['dpp'], $d['ppn']])->all());

        config(['tax-export.formats.keluaran.columns' => [['header' => 'NPWP', 'field' => 'party_tax_id'], ['header' => 'Kode', 'value' => '040'], ['header' => 'Nilai PPN', 'field' => 'ppn']]]);
        Excel::fake();
        $this->get(route('taxes.export', ['masa' => '2026-11', 'arah' => 'keluaran', 'format' => 'csv']))->assertOk();
        Excel::assertDownloaded('faktur-keluaran-2026-11.csv', fn ($export) => $export->headings() === ['NPWP', 'Kode', 'Nilai PPN']
            && $export->array() === [['02.111.222.3-444.000', '040', 22000.0]]);

        $this->get(route('taxes.ppn', ['tahun' => 2026]))->assertOk()->assertSee('November 2026')->assertSee('(Rp 88.000)');
        $this->get(route('taxes.documents', ['masa' => '2026-11', 'arah' => 'masukan']))->assertOk()->assertSee('CV Teknik')->assertSee('FP-0012');
    }

    public function test_rekap_bukti_potong_dan_isi_nomornya(): void
    {
        $tagihan = $this->tagihan('2026-11-20');

        $this->get(route('taxes.withholdings', ['masa' => '2026-11']))->assertOk()->assertSee($tagihan->number)->assertSee('Rp 20.000');
        $this->post(route('taxes.withholdings.update', $tagihan), ['withholding_slip_number' => 'BP-2026-0099', 'withholding_slip_date' => '2026-12-04'])->assertSessionHasNoErrors();

        $this->assertSame('BP-2026-0099', $tagihan->fresh()->withholding_slip_number);
        $this->assertSame(1, app(TaxReportService::class)->withholdings('2026-11', 'pph23')->count());
        $this->assertSame(0, app(TaxReportService::class)->withholdings('2026-11', 'pph4_2')->count());
    }

    public function test_tanpa_izin_pajak_ditolak(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('taxes.ppn'))->assertForbidden();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->post(route('settings.tax-rates.store'), [])->assertForbidden();
    }
}
