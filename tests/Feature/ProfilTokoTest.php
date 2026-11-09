<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\SaleService;
use App\Services\Settings\StoreProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class ProfilTokoTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $superAdmin;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->superAdmin = $this->buatPengguna(RoleName::SuperAdmin);
        $this->admin = $this->buatPengguna(RoleName::Admin);
        $this->kasir = $this->buatPengguna(RoleName::Kasir);
        Customer::umum();
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'nama_toko' => 'Dkriuk Jabong',
            'nama_pemilik' => 'Iyan Hermawan',
            'alamat' => 'Jl. Merdeka No. 10, Bandung',
            'telepon' => '0812-3456-7890',
            'instagram' => 'dkriukjabong',
            'tiktok' => 'dkriukjabong',
            'email' => 'halo@dkriukjabong.com',
            'npwp' => '01.234.567.8-901.000',
        ], $ubah);
    }

    // ---------------- Bawaan ----------------

    public function test_sebelum_diisi_profil_memakai_bawaan_yang_masuk_akal(): void
    {
        $profil = app(StoreProfileService::class)->ambil();

        $this->assertSame(config('app.name'), $profil['nama_toko'], 'Nama toko bawaan dari config app.name.');
        $this->assertSame('', $profil['nama_pemilik']);
        $this->assertSame('', $profil['alamat']);
        $this->assertSame('', $profil['instagram']);
        $this->assertSame(58, $profil['lebar_struk_mm'], 'Lebar bawaan 58 mm.');
        $this->assertNotSame('', $profil['footer_struk']);
    }

    public function test_lebar_tidak_dikenal_dikembalikan_ke_58(): void
    {
        Setting::simpan(['lebar_struk_mm' => '72']);

        $this->assertSame(58, app(StoreProfileService::class)->lebar());
    }

    public function test_kolom_yang_dikosongkan_kembali_ke_bawaan(): void
    {
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload());

        // Kosongkan lagi: nilai kosong berarti "pakai bawaan", bukan string kosong.
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload([
            'nama_pemilik' => '', 'alamat' => '', 'instagram' => '', 'tiktok' => '',
        ]));

        $profil = app(StoreProfileService::class)->ambil();
        $this->assertSame('', $profil['nama_pemilik']);
        $this->assertSame('', $profil['alamat']);
        $this->assertSame('', $profil['instagram']);
    }

    // ---------------- Halaman pengaturan ----------------

    public function test_super_admin_dapat_membuka_dan_menyimpan_profil_toko(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Profil Toko')
            ->assertSee('Nama toko')
            ->assertSee('Nama pemilik')
            ->assertSee('Instagram')
            ->assertSee('TikTok')
            ->assertSee('NPWP')
            ->assertSee('Unggah logo');

        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.edit'));

        $profil = app(StoreProfileService::class)->ambil();
        $this->assertSame('Dkriuk Jabong', $profil['nama_toko']);
        $this->assertSame('Iyan Hermawan', $profil['nama_pemilik']);
        $this->assertSame('Jl. Merdeka No. 10, Bandung', $profil['alamat']);
        $this->assertSame('0812-3456-7890', $profil['telepon']);
        $this->assertSame('dkriukjabong', $profil['instagram']);
        $this->assertSame('dkriukjabong', $profil['tiktok']);
        $this->assertSame('halo@dkriukjabong.com', $profil['email']);
        $this->assertSame('01.234.567.8-901.000', $profil['npwp']);
    }

    public function test_menyimpan_profil_tidak_mengubah_pengaturan_struk(): void
    {
        Setting::simpan(['lebar_struk_mm' => '80', 'footer_struk' => 'Sampai jumpa']);

        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload())->assertSessionHasNoErrors();

        $profil = app(StoreProfileService::class)->ambil();
        $this->assertSame(80, $profil['lebar_struk_mm']);
        $this->assertSame('Sampai jumpa', $profil['footer_struk']);
    }

    public function test_halaman_menampilkan_nilai_yang_tersimpan(): void
    {
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload(['nama_toko' => 'Warung Aiti']));

        $this->actingAs($this->superAdmin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Warung Aiti')
            ->assertSee('Iyan Hermawan')
            ->assertSee('dkriukjabong');
    }

    public function test_admin_dan_kasir_tidak_bisa_mengubah_profil_toko(): void
    {
        foreach ([$this->admin, $this->kasir] as $user) {
            $this->actingAs($user)->get(route('settings.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('settings.update'), $this->payload())->assertForbidden();
        }

        $this->assertSame(0, Setting::count(), 'Tidak ada pengaturan yang tersimpan dari percobaan tanpa izin.');
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('settings.edit'))->assertRedirect(route('login'));
    }

    // ---------------- Validasi ----------------

    public function test_nama_toko_wajib_diisi(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['nama_toko' => '']))
            ->assertSessionHasErrors('nama_toko');
    }

    #[TestWith(['01.234.567.8-901.000'])]
    #[TestWith(['3201234567890001'])]
    public function test_npwp_15_atau_16_digit_diterima(string $npwp): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['npwp' => $npwp]))
            ->assertSessionHasNoErrors();
    }

    public function test_npwp_dengan_jumlah_digit_lain_ditolak(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['npwp' => '12.345.678']))
            ->assertSessionHasErrors(['npwp' => 'NPWP harus 15 atau 16 digit.']);
    }

    public function test_email_toko_harus_alamat_email(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['email' => 'bukan-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_telepon_menolak_karakter_selain_angka_dan_tanda_umum(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['telepon' => '0812<script>']))
            ->assertSessionHasErrors('telepon');
    }

    public function test_username_sosmed_menerima_tanda_at_dan_membuangnya(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload([
                'instagram' => '@dkriuk.jabong',
                'tiktok' => ' @dkriukjabong ',
            ]))
            ->assertSessionHasNoErrors();

        $profil = app(StoreProfileService::class)->ambil();
        $this->assertSame('dkriuk.jabong', $profil['instagram'], 'Tanda @ dibuang.');
        $this->assertSame('dkriukjabong', $profil['tiktok'], 'Spasi dan @ dibuang.');
    }

    public function test_username_sosmed_menolak_karakter_tidak_sah(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['instagram' => 'nama toko']))
            ->assertSessionHasErrors('instagram');
    }

    // ---------------- Terpakai di struk ----------------

    private function jual(): Sale
    {
        $produk = Product::factory()->stok(20)->create([
            'name' => 'Ayam Geprek', 'sku' => 'AYM-1', 'selling_price' => 10000, 'purchase_price' => 7000,
        ]);

        return app(SaleService::class)->buat([
            'customer_id' => null,
            'items' => [['product_id' => $produk->id, 'quantity' => '2', 'discount' => '0']],
            'discount' => '0', 'payment_method' => 'cash', 'paid_amount' => '20000',
        ], $this->kasir);
    }

    public function test_struk_memakai_identitas_dari_profil_toko(): void
    {
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload());
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Dkriuk Jabong')
            ->assertSee('Iyan Hermawan')
            ->assertSee('Jl. Merdeka No. 10, Bandung')
            ->assertSee('0812-3456-7890')
            ->assertSee('IG @dkriukjabong')
            ->assertSee('TikTok @dkriukjabong');
    }

    public function test_struk_tidak_menampilkan_bagian_kosong(): void
    {
        // Hanya nama toko yang diisi; sisanya dibiarkan kosong.
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload([
            'nama_pemilik' => '', 'alamat' => '', 'telepon' => '', 'instagram' => '', 'tiktok' => '',
        ]));
        $jual = $this->jual();

        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Dkriuk Jabong')
            ->assertDontSee('Telp ')
            ->assertDontSee('IG @')
            ->assertDontSee('TikTok @');
    }

    public function test_perubahan_profil_langsung_terpakai_tanpa_ubah_env(): void
    {
        $jual = $this->jual();

        // Struk pertama: bawaan.
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertDontSee('Warung Aiti');

        // Pemilik mengubah nama toko dari aplikasi.
        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['nama_toko' => 'Warung Aiti']));

        // Struk berikutnya memakai nama baru, tanpa restart atau sentuh .env.
        $this->actingAs($this->kasir)
            ->get(route('sales.receipt', $jual))
            ->assertOk()
            ->assertSee('Warung Aiti');
    }

    // ---------------- Logo ----------------

    public function test_logo_diunggah_ke_penyimpanan_publik(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)]))
            ->assertSessionHasNoErrors();

        $profil = app(StoreProfileService::class)->ambil();
        Storage::disk('public')->assertExists($profil['logo']);
        $this->assertSame(Storage::disk('public')->url($profil['logo']), $profil['logo_url']);
    }

    public function test_logo_baru_menggantikan_dan_menghapus_berkas_lama(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload(['logo' => UploadedFile::fake()->image('lama.png')]));
        $lama = app(StoreProfileService::class)->ambil()['logo'];

        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload(['logo' => UploadedFile::fake()->image('baru.png')]));

        $baru = app(StoreProfileService::class)->ambil()['logo'];
        $this->assertNotSame($lama, $baru);
        Storage::disk('public')->assertMissing($lama);
        Storage::disk('public')->assertExists($baru);
    }

    public function test_logo_bisa_dihapus(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload(['logo' => UploadedFile::fake()->image('logo.png')]));
        $lama = app(StoreProfileService::class)->ambil()['logo'];

        $this->actingAs($this->superAdmin)->put(route('settings.update'), $this->payload(['hapus_logo' => '1']))->assertSessionHasNoErrors();

        $profil = app(StoreProfileService::class)->ambil();
        $this->assertSame('', $profil['logo']);
        $this->assertNull($profil['logo_url']);
        Storage::disk('public')->assertMissing($lama);
    }

    public function test_logo_svg_ditolak(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin)
            ->put(route('settings.update'), $this->payload([
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ]))
            ->assertSessionHasErrors('logo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
