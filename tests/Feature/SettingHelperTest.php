<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\SettingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_membaca_nilai_dan_nilai_bawaan(): void
    {
        Setting::query()->create(['key' => 'tax.rate', 'value' => '12']);
        Setting::query()->create(['key' => 'tax.name', 'value' => '  ']);

        $this->assertSame('12', setting('tax.rate', '11'));
        $this->assertSame('PPN', setting('tax.name', 'PPN'), 'Nilai kosong dianggap belum diisi.');
        $this->assertNull(setting('belum.ada'));
    }

    public function test_menyimpan_lewat_helper_langsung_terbaca(): void
    {
        $this->assertNull(setting('receipt.auto_print'));

        setting(['receipt.auto_print' => true, 'tax.name' => 'PPN']);

        $this->assertSame('1', setting('receipt.auto_print'));
        $this->assertSame(['receipt.auto_print' => '1', 'tax.name' => 'PPN'], setting());
    }

    public function test_nilai_dibaca_sekali_lalu_diambil_dari_cache(): void
    {
        setting(['tax.rate' => '11']);
        setting('tax.rate');

        DB::enableQueryLog();
        setting('tax.rate');
        setting('tax.name');
        app()->forgetScopedInstances(); // request baru: ingatan per request hilang, cache aplikasi tetap
        setting('tax.rate');

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_perubahan_lewat_model_menghapus_cache(): void
    {
        setting(['tax.rate' => '11']);
        $this->assertSame('11', setting('tax.rate'));

        Setting::query()->where('key', 'tax.rate')->first()->update(['value' => '12']);
        app()->forgetScopedInstances();

        $this->assertSame('12', setting('tax.rate'));
    }

    public function test_cache_dihapus_lagi_setelah_transaksi_selesai(): void
    {
        setting(['tax.rate' => '11']);
        setting('tax.rate');

        DB::transaction(function () {
            setting(['tax.rate' => '12']);
            // Request lain membaca saat transaksi belum commit dan mengisi cache dengan nilai lama.
            cache()->forever(SettingStore::CACHE_KEY, ['tax.rate' => '11']);
        });
        app()->forgetScopedInstances();

        $this->assertSame('12', setting('tax.rate'));
    }
}
