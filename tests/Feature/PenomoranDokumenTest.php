<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\NumberingReset;
use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Services\Settings\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class PenomoranDokumenTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $pemilik;

    private User $kasir;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        $this->produk = Product::factory()->stok(100)->create(['selling_price' => 10000]);
        Customer::umum();
        $this->travelTo('2026-11-09 10:00:00');
    }

    /** @param array<string, array{pattern: string, reset: string}> $ubah */
    private function formats(array $ubah = []): array
    {
        $formats = [];
        foreach (DocumentType::cases() as $type) {
            $formats[$type->value] = ['pattern' => $type->defaultPattern(), 'reset' => $type->defaultReset()->value];
        }

        return ['formats' => array_replace($formats, $ubah)];
    }

    private function aturPenjualan(string $pola, NumberingReset $reset): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.numbering.update'), $this->formats([DocumentType::Sale->value => ['pattern' => $pola, 'reset' => $reset->value]]))
            ->assertSessionHasNoErrors();
    }

    private function jual(int $jumlah = 1): Sale
    {
        return app(SaleService::class)->buat([
            'items' => [['product_id' => $this->produk->id, 'quantity' => (string) $jumlah, 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '100000000',
        ], $this->kasir);
    }

    // ---------------- Format & urutan ----------------

    public function test_pola_bulanan_menghasilkan_nomor_berurutan_seperti_inv(): void
    {
        $this->aturPenjualan('INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly);

        $this->assertSame('INV/2026/11/0001', $this->jual()->number);
        $this->assertSame('INV/2026/11/0002', $this->jual()->number);
    }

    public function test_nomor_urut_kembali_ke_1_saat_periode_reset_berganti(): void
    {
        $this->aturPenjualan('INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly);
        $this->jual();
        $this->jual();

        $this->travelTo('2026-12-01 08:00:00');

        $this->assertSame('INV/2026/12/0001', $this->jual()->number);
    }

    public function test_reset_tahunan_tetap_berlanjut_saat_bulan_berganti(): void
    {
        $this->aturPenjualan('INV/{YY}{MM}/{#####}', NumberingReset::Yearly);
        $this->jual();

        $this->travelTo('2026-12-15 08:00:00');

        $this->assertSame('INV/2612/00002', $this->jual()->number);
    }

    public function test_nomor_yang_sudah_dipakai_dilewati(): void
    {
        // Penjualan lama kebetulan sudah memakai nomor yang akan dibuat pola baru.
        $this->aturPenjualan('POS-{YYYY}{MM}{DD}-{####}', NumberingReset::Daily);
        $this->jual();
        $this->jual();
        $this->aturPenjualan('POS-{YYYY}{MM}{DD}-{####}', NumberingReset::Monthly);

        $this->assertSame('POS-20261109-0003', $this->jual()->number);
    }

    public function test_transaksi_yang_gagal_tidak_menghabiskan_nomor(): void
    {
        $this->aturPenjualan('INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly);

        try {
            $this->jual(1000); // stok hanya 100
            $this->fail('Penjualan melebihi stok seharusnya ditolak.');
        } catch (AturanBisnisException) {
        }

        $this->assertSame('INV/2026/11/0001', $this->jual()->number);
    }

    public function test_pola_baru_tidak_mengubah_nomor_dokumen_lama(): void
    {
        $lama = $this->jual();

        $this->aturPenjualan('INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly);
        $this->jual();

        $this->assertSame('POS-20261109-0001', $lama->fresh()->number);
    }

    // ---------------- Aturan pola ----------------

    /** @return array<string, array{string, NumberingReset, ?string}> */
    public static function polaProvider(): array
    {
        return [
            'tanpa nomor urut' => ['INV/{YYYY}/{MM}', NumberingReset::Monthly, 'Pola harus memuat tepat satu nomor urut, misal {####}.'],
            'dua nomor urut' => ['{###}-{####}', NumberingReset::Never, 'Pola harus memuat tepat satu nomor urut, misal {####}.'],
            'penanda tidak dikenal' => ['INV/{TAHUN}/{####}', NumberingReset::Never, 'Penanda tidak dikenal. Gunakan {YYYY}, {YY}, {MM}, {DD}, dan {####}.'],
            'spasi' => ['INV {####}', NumberingReset::Never, 'Selain penanda, pola hanya boleh berisi huruf, angka, dan tanda / - . _'],
            'reset harian tanpa tanggal' => ['INV/{YYYY}/{MM}/{####}', NumberingReset::Daily, 'Nomor direset setiap hari, jadi pola harus memuat {DD} agar nomor tidak berulang.'],
            'reset bulanan tanpa bulan' => ['INV/{YYYY}/{####}', NumberingReset::Monthly, 'Nomor direset setiap bulan, jadi pola harus memuat {MM} agar nomor tidak berulang.'],
            'reset tahunan tanpa tahun' => ['INV/{MM}/{####}', NumberingReset::Yearly, 'Nomor direset setiap tahun, jadi pola harus memuat {YYYY} atau {YY} agar nomor tidak berulang.'],
            'terlalu panjang' => ['PENJUALAN-TOKO-PUSAT-{YYYY}-{########}', NumberingReset::Yearly, 'Nomor yang dihasilkan terlalu panjang (maksimal 30 karakter).'],
            'sah: bulanan' => ['INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly, null],
            'sah: tahun 2 digit' => ['J{YY}{MM}{DD}{###}', NumberingReset::Daily, null],
            'sah: tanpa reset' => ['TRX-{######}', NumberingReset::Never, null],
        ];
    }

    #[DataProvider('polaProvider')]
    public function test_aturan_pola(string $pola, NumberingReset $reset, ?string $pesan): void
    {
        $this->assertSame($pesan, app(DocumentNumberService::class)->patternError(DocumentType::Sale, $pola, $reset));
    }

    public function test_pola_tidak_sah_ditolak_dengan_pesan_dan_tidak_disimpan(): void
    {
        $this->actingAs($this->pemilik)
            ->put(route('settings.numbering.update'), $this->formats([
                DocumentType::Purchase->value => ['pattern' => 'BELI/{YYYY}/{####}', 'reset' => NumberingReset::Monthly->value],
            ]))
            ->assertSessionHasErrors(['formats.purchase.pattern' => 'Nomor direset setiap bulan, jadi pola harus memuat {MM} agar nomor tidak berulang.']);

        $this->assertSame(DocumentType::Purchase->defaultPattern(), app(DocumentNumberService::class)->format(DocumentType::Purchase)['pattern']);
    }

    public function test_batas_panjang_cuti_lebih_pendek(): void
    {
        $this->assertSame(
            'Nomor yang dihasilkan terlalu panjang (maksimal 20 karakter).',
            app(DocumentNumberService::class)->patternError(DocumentType::Leave, 'CUTI-{YYYY}-{MM}-{########}', NumberingReset::Never),
        );
    }

    // ---------------- Halaman & hak akses ----------------

    public function test_halaman_menampilkan_semua_dokumen_dan_contoh_nomor(): void
    {
        $respons = $this->actingAs($this->pemilik)->get(route('settings.numbering.edit'))->assertOk();

        foreach (DocumentType::cases() as $type) {
            $respons->assertSee($type->label());
        }
        $respons->assertSee('POS-20261109-0001');
    }

    public function test_perubahan_tercatat_di_log_aktivitas(): void
    {
        $this->aturPenjualan('INV/{YYYY}/{MM}/{####}', NumberingReset::Monthly);

        $log = Activity::query()->where('description', 'Penomoran dokumen diubah')->sole();
        $this->assertTrue($log->causer->is($this->pemilik));
        $this->assertSame(['Penjualan kasir' => 'INV/{YYYY}/{MM}/{####} (Setiap bulan)'], $log->properties['attributes']);
    }

    public function test_admin_tanpa_izin_tidak_bisa_mengubah_format(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);

        $this->actingAs($admin)->get(route('settings.numbering.edit'))->assertForbidden();
        $this->actingAs($admin)
            ->put(route('settings.numbering.update'), $this->formats([DocumentType::Sale->value => ['pattern' => 'X-{####}', 'reset' => 'never']]))
            ->assertForbidden();

        $this->assertSame(DocumentType::Sale->defaultPattern(), app(DocumentNumberService::class)->format(DocumentType::Sale)['pattern']);
    }
}
