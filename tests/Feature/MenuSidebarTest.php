<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class MenuSidebarTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
    }

    public function test_konfigurasi_menu_punya_key_unik_ikon_dan_route_untuk_menu_aktif(): void
    {
        $keys = [];

        foreach (config('menu') as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
                $this->assertNotEmpty($item['icon'] ?? null, "Menu {$item['key']} wajib punya ikon.");

                foreach ($item['children'] ?? [] as $child) {
                    $keys[] = $child['key'];
                }

                foreach ($item['children'] ?? [$item] as $leaf) {
                    if (Menu::find($leaf['key'])['status'] === Menu::ENABLED) {
                        $this->assertTrue(Route::has($leaf['route'] ?? ''), "Route menu {$leaf['key']} belum terdaftar.");
                    }
                }
            }
        }

        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_kasir_hanya_melihat_menu_sesuai_hak_akses(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Transaksi Baru')
            ->assertSee('Jadwal Kerja')
            ->assertDontSee('Faktur Pembelian')
            ->assertDontSee('Akuntansi')
            ->assertDontSee('Profil Toko');
    }

    public function test_menu_segera_hadir_tampil_dengan_badge_dan_menuju_halaman_pengganti(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Akuntan))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Akuntansi')
            ->assertSee(route('coming-soon', 'accounting-coa'))
            ->assertSee('Segera Hadir');
    }

    public function test_menu_hidden_tanpa_izin_tanpa_route_dan_induk_kosong_disembunyikan(): void
    {
        config(['menu' => [[
            'label' => 'Uji',
            'items' => [
                ['key' => 'uji-hidden', 'label' => 'Tersembunyi', 'icon' => 'home', 'route' => 'dashboard', 'status' => 'hidden'],
                ['key' => 'uji-induk', 'label' => 'Induk', 'icon' => 'home', 'children' => [
                    ['key' => 'uji-anak', 'label' => 'Anak', 'route' => 'users.index', 'permission' => 'access.users.view'],
                ]],
                ['key' => 'uji-tanpa-route', 'label' => 'Tanpa route', 'icon' => 'home', 'route' => 'route.yang.tidak.ada'],
                ['key' => 'uji-tampil', 'label' => 'Tampil', 'icon' => 'home', 'route' => 'dashboard'],
            ],
        ]]]);

        $tree = Menu::tree($this->buatPengguna(RoleName::Kasir));

        $this->assertSame(['uji-tampil'], array_column($tree[0]['items'], 'key'));
    }

    public function test_menu_aktif_ditandai_dan_breadcrumb_menunjukkan_posisi_halaman(): void
    {
        $response = $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->get(route('products.create'))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('products.index'), '#').'"\s+aria-current="page"#',
            $response->getContent(),
        );
        $response->assertSeeInOrder(['aria-label="Breadcrumb"', 'Inventori', 'Produk', 'Tambah produk'], false);
    }

    public function test_halaman_indeks_menampilkan_deskripsi_dari_menu(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Admin))
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Katalog produk, harga, dan stok minimum.');
    }

    public function test_halaman_kasir_memakai_layout_layar_penuh_tanpa_sidebar(): void
    {
        $this->actingAs($this->buatPengguna(RoleName::Kasir))
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('title="Keluar dari kasir ke dashboard"', false)
            ->assertDontSee('aria-label="Menu utama"', false);
    }
}
