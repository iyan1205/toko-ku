<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

class KomponenUiTest extends TestCase
{
    public function test_tombol_menjadi_tautan_bila_diberi_href_dan_tombol_biasa_bila_tidak(): void
    {
        $this->blade('<x-ui.button href="/produk">Produk</x-ui.button>')
            ->assertSee('href="/produk"', false)
            ->assertDontSee('<button', false);

        $this->blade('<x-ui.button type="submit">Simpan</x-ui.button>')
            ->assertSee('type="submit"', false)
            ->assertDontSee('href=', false);
    }

    public function test_isian_menampilkan_error_dan_nilai_lama_untuk_nama_array(): void
    {
        $this->withViewErrors(['items.0.quantity' => 'Jumlah wajib diisi.']);
        $this->denganInputLama(['items' => [['quantity' => '5']]]);

        $this->blade('<x-ui.input name="items[0][quantity]" label="Jumlah" />')
            ->assertSee('Jumlah wajib diisi.')
            ->assertSee('value="5"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_isian_tidak_wajib_diberi_tanda_opsional_dan_isian_wajib_tidak(): void
    {
        $this->blade('<x-ui.input name="sku" label="SKU" />')
            ->assertSee('(opsional)');

        $this->blade('<x-ui.input name="name" label="Nama" required />')
            ->assertDontSee('(opsional)');
    }

    public function test_judul_kolom_membalik_arah_urutan_dan_mempertahankan_filter(): void
    {
        $this->app->instance('request', Request::create('/produk', 'GET', [
            'cari' => 'ayam', 'sort' => 'name', 'dir' => 'asc', 'page' => '3',
        ]));

        $this->blade('<x-ui.table.th sort="name">Nama</x-ui.table.th><x-ui.table.th sort="price">Harga</x-ui.table.th>')
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('http://localhost/produk?cari=ayam&amp;sort=name&amp;dir=desc', false)
            ->assertSee('http://localhost/produk?cari=ayam&amp;sort=price&amp;dir=asc', false)
            ->assertDontSee('page=', false);
    }

    public function test_bar_filter_menampilkan_reset_hanya_bila_ada_filter_terisi(): void
    {
        $this->app->instance('request', Request::create('/produk', 'GET', ['cari' => '', 'sort' => 'name', 'dir' => 'desc']));

        $this->blade('<x-ui.filter-bar />')
            ->assertSee('name="sort" value="name"', false)
            ->assertDontSee('Reset');

        $this->app->instance('request', Request::create('/produk', 'GET', ['cari' => 'ayam']));

        $this->blade('<x-ui.filter-bar />')
            ->assertSee('value="ayam"', false)
            ->assertSee('Reset');
    }

    public function test_toast_menampilkan_pesan_flash_dari_session(): void
    {
        session()->flash('success', 'Produk tersimpan.');
        session()->flash('error', 'Stok tidak mencukupi.');

        $this->blade('<x-ui.toast />')
            ->assertSee('Produk tersimpan.')
            ->assertSee('Stok tidak mencukupi.');
    }

    public function test_kotak_centang_mengikuti_input_lama_setelah_validasi_gagal(): void
    {
        $this->blade('<x-ui.checkbox name="is_active" label="Aktif" checked />')
            ->assertSee('checked', false);

        $this->denganInputLama(['permissions' => ['sdm.attendances.record']]);

        $this->blade('<x-ui.checkbox name="is_active" label="Aktif" checked />')
            ->assertDontSee('checked', false);
        $this->blade('<x-ui.checkbox name="permissions[]" value="sdm.attendances.record" label="Catat absensi" />')
            ->assertSee('checked', false);
        $this->blade('<x-ui.checkbox name="permissions[]" value="pos.transactions.create" label="Buat transaksi" checked />')
            ->assertDontSee('checked', false);
    }

    /** Input lama seperti setelah redirect validasi gagal; old() membaca sesi lewat request. */
    private function denganInputLama(array $input): void
    {
        session()->flashInput($input);
        request()->setLaravelSession(app('session.store'));
    }
}
