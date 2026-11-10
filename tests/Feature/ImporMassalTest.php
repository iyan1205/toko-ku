<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Kerangka impor: templat, unggah → pratinjau per baris, proses baris valid, laporan kesalahan, dan izin. */
class ImporMassalTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
    }

    private function csv(string $isi): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pelanggan.csv', $isi);
    }

    private function unggah(string $isi, string $jenis = 'customers'): ImportBatch
    {
        $this->actingAs($this->admin)->post(route("imports.{$jenis}.preview"), ['file' => $this->csv($isi)])->assertSessionHasNoErrors()->assertRedirect();

        return ImportBatch::latest('id')->firstOrFail();
    }

    public function test_pratinjau_memeriksa_setiap_baris_tanpa_menyimpan_data(): void
    {
        $lama = Customer::factory()->create(['code' => 'CUS-0100', 'name' => 'Pak Lama']);
        $csv = "Kode,Nama,Telepon,Email,Alamat,Catatan\n"
            .",Bu Siti,81234567890,siti@contoh.id,Jl. Melati,\n"
            ."CUS-0100,Pak Lama Baru,,,,\n"
            .",,0812,bukan-email,,\n"
            ."CUS-9999,Tidak Ada,,,,\n"
            ."CUS-0100,Ganda,,,,\n";

        $batch = $this->unggah($csv);

        $this->assertSame([5, 2, 3], [$batch->total_rows, $batch->valid_rows, $batch->invalid_rows]);
        $this->assertSame(1, Customer::where('is_default', false)->count(), 'Pratinjau belum mengubah data.');
        $this->assertSame('Pak Lama', $lama->fresh()->name);

        $errors = collect($batch->rows)->keyBy('line')->map(fn ($r) => $r['errors']);
        $this->assertSame([], $errors[2]);
        $this->assertContains('Nama wajib diisi.', $errors[4]);
        $this->assertContains('Email harus berupa alamat email yang valid.', $errors[4]);
        $this->assertContains('Kode CUS-9999 tidak ditemukan. Kosongkan kolom Kode untuk pelanggan baru.', $errors[5]);
        $this->assertContains('Kode CUS-0100 muncul ganda (juga di baris 3).', $errors[6]);

        $this->get(route('imports.customers.show', $batch))->assertOk()
            ->assertSee('Proses 2 baris, lewati 3 yang salah')
            ->assertSee('Kode CUS-9999 tidak ditemukan');
    }

    public function test_proses_menyimpan_baris_valid_dan_mencatat_hasilnya(): void
    {
        $lama = Customer::factory()->create(['code' => 'CUS-0100', 'name' => 'Pak Lama']);
        $batch = $this->unggah("Kode,Nama,Telepon\n,Bu Siti,81234567890\nCUS-0100,Pak Lama Baru,\n,,\n,Tanpa Nama Salah Email,abc\n");

        $this->post(route('imports.customers.commit', $batch))->assertRedirect(route('imports.customers.show', $batch))
            ->assertSessionHas('success', 'Impor pelanggan selesai: 1 ditambahkan, 1 diperbarui, 1 baris salah dilewati.');

        $baru = Customer::where('name', 'Bu Siti')->sole();
        $this->assertMatchesRegularExpression('/^CUS-\d{4}$/', $baru->code);
        $this->assertSame('081234567890', $baru->phone, 'Angka 0 di depan nomor HP dikembalikan.');
        $this->assertSame('Pak Lama Baru', $lama->fresh()->name);

        $batch->refresh();
        $this->assertSame([1, 1], [$batch->created_count, $batch->updated_count]);
        $this->assertFalse($batch->isPreviewed());
        $this->assertTrue(Activity::where('log_name', 'impor')->where('event', 'imported')->exists());

        // Tidak bisa diproses dua kali.
        $this->post(route('imports.customers.commit', $batch))->assertSessionHas('error', 'Impor ini sudah diproses.');
        $this->assertSame(1, Customer::where('name', 'Bu Siti')->count());
    }

    public function test_kolom_wajib_berkas_kosong_dan_jenis_berkas_ditolak(): void
    {
        $this->actingAs($this->admin)->post(route('imports.customers.preview'), ['file' => $this->csv("Telepon,Email\n0812,a@b.id\n")])
            ->assertSessionHas('error', 'Kolom wajib tidak ditemukan di baris judul: Nama. Pakai templat dari tombol Unduh templat.');
        $this->post(route('imports.customers.preview'), ['file' => $this->csv("Kode,Nama\n,\n")])
            ->assertSessionHas('error', 'Berkas tidak berisi data. Isi baris di bawah judul kolom templat.');
        $this->post(route('imports.customers.preview'), ['file' => UploadedFile::fake()->create('data.pdf', 5, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_templat_dan_laporan_kesalahan_diunduh(): void
    {
        $batch = $this->unggah("Kode,Nama,Email\n,Benar,\n,Salah,bukan-email\n");

        Excel::fake();
        $this->get(route('imports.customers.template'))->assertOk();
        Excel::assertDownloaded('templat-impor-pelanggan.xlsx');

        $this->get(route('imports.customers.errors', $batch))->assertOk();
        Excel::assertDownloaded("kesalahan-impor-pelanggan-{$batch->id}.xlsx", function ($export) {
            $baris = $export->array();

            return count($baris) === 1 && $baris[0][1] === 'Salah' && $baris[0][6] === 3 && str_contains($baris[0][7], 'Email');
        });
    }

    public function test_impor_supplier_dan_hak_akses(): void
    {
        $batch = $this->unggah("Kode,Nama,Kontak,NPWP\n,PT Sumber Rejeki,Pak Budi,01.234.567.8-901.000\n,CV Salah,,NPWP-SALAH\n", 'suppliers');
        $this->assertSame([1, 1], [$batch->valid_rows, $batch->invalid_rows]);
        $this->post(route('imports.suppliers.commit', $batch))->assertSessionHas('success');
        $this->assertSame('Pak Budi', Supplier::where('name', 'PT Sumber Rejeki')->value('contact_name'));

        // Hasil impor hanya bisa dibuka pengunggahnya; kasir tidak punya izin impor.
        $pemilik = $this->buatPengguna(RoleName::Pemilik);
        $this->actingAs($pemilik)->get(route('imports.suppliers.show', $batch))->assertNotFound();
        $this->actingAs($this->buatPengguna(RoleName::Kasir))->get(route('imports.customers.create'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('customers.index'))->assertSee(route('imports.customers.create'), false);
        $this->get(route('imports.customers.create'))->assertOk()->assertSee('Kolom templat')->assertSee('Unduh templat');
    }
}
