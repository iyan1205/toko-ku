<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Enums\StatusCuti;
use App\Enums\StatusPersetujuan;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class CutiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    private User $admin;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->travelTo('2026-11-02 09:00:00');
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Kasir Satu']);
        $this->admin = $this->buatPengguna(RoleName::Admin, ['name' => 'Admin Toko']);
        $this->pemilik = $this->buatPengguna(RoleName::SuperAdmin, ['name' => 'Pemilik']);
    }

    private function ajukan(User $pemohon, JenisCuti $jenis, array $ubah = []): TestResponse
    {
        return $this->actingAs($pemohon)->post(route('leaves.store'), array_merge([
            'jenis_cuti_id' => $jenis->id,
            'tanggal_mulai' => '2026-11-10',
            'tanggal_selesai' => '2026-11-12',
            'alasan' => 'Acara keluarga',
        ], $ubah));
    }

    private function setujui(User $penyetuju, Cuti $cuti, ?string $catatan = null): TestResponse
    {
        return $this->actingAs($penyetuju)->post(route('leaves.approve', $cuti), ['catatan' => $catatan]);
    }

    /** @return array<int, array{0:string, 1:string}> [tanggal, status] urut tanggal */
    private function absensiKasir(): array
    {
        return Absensi::query()->where('user_id', $this->kasir->id)->orderBy('tanggal')->get()
            ->map(fn (Absensi $a) => [$a->tanggal->toDateString(), $a->status->value])->all();
    }

    // ---------------- Pengajuan ----------------

    public function test_pengajuan_menyalin_alur_dan_menunggu_tahap_pertama(): void
    {
        $jenis = JenisCuti::factory()->kuota(12)->alur([RoleName::Admin, $this->pemilik])->create(['nama' => 'Cuti Tahunan']);

        $this->ajukan($this->kasir, $jenis)
            ->assertRedirect(route('leaves.show', Cuti::sole()))
            ->assertSessionHas('success', 'Pengajuan CT-00001 terkirim. Menunggu persetujuan Peran Admin.');

        $cuti = Cuti::sole();
        $this->assertSame('CT-00001', $cuti->nomor);
        $this->assertSame($this->kasir->id, $cuti->user_id);
        $this->assertSame(3, $cuti->jumlah_hari);
        $this->assertSame(StatusCuti::Menunggu, $cuti->status);
        $this->assertSame(1, $cuti->tahap_aktif);
        $this->assertSame(
            [[1, 'Admin', null, 'menunggu'], [2, null, $this->pemilik->id, 'menunggu']],
            $cuti->persetujuan->map(fn ($p) => [$p->urutan, $p->peran?->value, $p->approver_id, $p->status->value])->all(),
        );
        $this->assertSame([], $this->absensiKasir());
    }

    public function test_jenis_tanpa_tahap_langsung_disetujui_dan_tercatat_di_absensi(): void
    {
        $jenis = JenisCuti::factory()->create(['nama' => 'Sakit', 'status_absensi' => StatusAbsensi::Sakit]);

        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-02', 'tanggal_selesai' => '2026-11-02'])
            ->assertSessionHas('success', 'Pengajuan CT-00001 langsung disetujui dan tercatat di absensi.');

        $this->assertSame(StatusCuti::Disetujui, Cuti::sole()->status);
        $this->assertSame([['2026-11-02', 'sakit']], $this->absensiKasir());
        $this->assertSame($this->kasir->id, Absensi::sole()->recorded_by);
    }

    public function test_pengajuan_melebihi_sisa_kuota_ditolak_termasuk_yang_masih_menunggu(): void
    {
        $jenis = JenisCuti::factory()->kuota(5)->alur([RoleName::Admin])->create(['nama' => 'Cuti Tahunan']);
        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-03', 'tanggal_selesai' => '2026-11-05']);

        $this->ajukan($this->kasir, $jenis)
            ->assertSessionHas('error', 'Sisa kuota Cuti Tahunan tahun 2026 tinggal 2 hari, sedangkan pengajuan ini 3 hari.');

        $this->assertSame(1, Cuti::count());
    }

    public function test_kuota_dihitung_per_tahun_tanggal_mulai(): void
    {
        $jenis = JenisCuti::factory()->kuota(3)->create();
        Cuti::factory()->for($this->kasir)->for($jenis)->tanggal('2025-12-01', '2025-12-03', 3)->create();

        $this->ajukan($this->kasir, $jenis)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(2, Cuti::count());
    }

    public function test_tanggal_yang_bertabrakan_dengan_pengajuan_lain_ditolak(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis);

        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-12', 'tanggal_selesai' => '2026-11-13'])
            ->assertSessionHas('error', 'Tanggal ini bertabrakan dengan pengajuan CT-00001 (10-11-2026 s/d 12-11-2026).');

        $this->assertSame(1, Cuti::count());
    }

    public function test_cuti_melewati_pergantian_tahun_ditolak(): void
    {
        $jenis = JenisCuti::factory()->create();

        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-12-30', 'tanggal_selesai' => '2027-01-02'])
            ->assertSessionHas('error', 'Cuti tidak boleh melewati pergantian tahun. Ajukan terpisah untuk tiap tahun.');

        $this->assertSame(0, Cuti::count());
    }

    public function test_tanggal_selesai_sebelum_tanggal_mulai_ditolak(): void
    {
        $jenis = JenisCuti::factory()->create();

        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-12', 'tanggal_selesai' => '2026-11-10'])
            ->assertSessionHasErrors(['tanggal_selesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);

        $this->assertSame(0, Cuti::count());
    }

    public function test_jenis_cuti_nonaktif_tidak_dapat_diajukan(): void
    {
        $jenis = JenisCuti::factory()->nonaktif()->create();

        $this->ajukan($this->kasir, $jenis)->assertSessionHasErrors(['jenis_cuti_id' => 'Jenis cuti ini tidak tersedia.']);

        $this->assertSame(0, Cuti::count());
    }

    public function test_pengajuan_tanpa_isian_ditolak(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('leaves.store'), [])
            ->assertSessionHasErrors(['jenis_cuti_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan']);
    }

    public function test_form_pengajuan_hanya_menampilkan_jenis_cuti_aktif(): void
    {
        JenisCuti::factory()->create(['nama' => 'Cuti Tahunan']);
        JenisCuti::factory()->nonaktif()->create(['nama' => 'Cuti Lama']);

        $this->actingAs($this->kasir)
            ->get(route('leaves.create'))
            ->assertOk()
            ->assertSee('Cuti Tahunan')
            ->assertDontSee('Cuti Lama');
    }

    // ---------------- Persetujuan bertingkat ----------------

    public function test_alur_dua_tahap_disetujui_berurutan_lalu_tercatat_di_absensi(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Kasir, ['name' => 'Penyelia']);
        $jenis = JenisCuti::factory()->alur([RoleName::Admin, $penyelia])->create(['nama' => 'Cuti Tahunan']);
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->setujui($this->admin, $cuti, 'Silakan')
            ->assertRedirect(route('leaves.show', $cuti))
            ->assertSessionHas('success', 'Tahap disetujui. Pengajuan CT-00001 lanjut ke Penyelia.');

        $cuti->refresh();
        $this->assertSame(StatusCuti::Menunggu, $cuti->status);
        $this->assertSame(2, $cuti->tahap_aktif);
        $tahap1 = $cuti->persetujuan->first();
        $this->assertSame(StatusPersetujuan::Disetujui, $tahap1->status);
        $this->assertSame($this->admin->id, $tahap1->diputuskan_oleh);
        $this->assertSame('Silakan', $tahap1->catatan);
        $this->assertSame([], $this->absensiKasir());

        $this->setujui($penyelia, $cuti)
            ->assertSessionHas('success', 'Pengajuan CT-00001 disetujui penuh dan tercatat di absensi.');

        $cuti->refresh();
        $this->assertSame(StatusCuti::Disetujui, $cuti->status);
        $this->assertNull($cuti->tahap_aktif);
        $this->assertSame([['2026-11-10', 'cuti'], ['2026-11-11', 'cuti'], ['2026-11-12', 'cuti']], $this->absensiKasir());
        $this->assertSame(['Cuti Tahunan · CT-00001'], Absensi::query()->distinct()->pluck('keterangan')->all());
        $this->assertSame([$penyelia->id], Absensi::query()->distinct()->pluck('recorded_by')->all());
    }

    public function test_approver_tahap_berikutnya_tidak_dapat_mendahului_tahap_sebelumnya(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Kasir);
        $jenis = JenisCuti::factory()->alur([RoleName::Admin, $penyelia])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->setujui($penyelia, $cuti)->assertForbidden();

        $this->assertSame(1, $cuti->refresh()->tahap_aktif);
        $this->assertSame(StatusPersetujuan::Menunggu, $cuti->persetujuan->first()->status);
    }

    public function test_pengguna_tanpa_peran_tahap_tidak_dapat_memutuskan(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->setujui($this->buatPengguna(RoleName::Kasir), $cuti)->assertForbidden();

        $this->assertSame(StatusCuti::Menunggu, $cuti->refresh()->status);
    }

    public function test_pemohon_tidak_dapat_menyetujui_pengajuannya_sendiri(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->admin, $jenis);
        $cuti = Cuti::sole();

        $this->setujui($this->admin, $cuti)->assertForbidden();

        $this->assertSame(StatusCuti::Menunggu, $cuti->refresh()->status);
    }

    public function test_super_admin_dapat_memutuskan_tahap_yang_ditujukan_ke_orang_lain(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Admin);
        $jenis = JenisCuti::factory()->alur([$penyelia])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->setujui($this->pemilik, $cuti)->assertSessionHas('success');

        $this->assertSame(StatusCuti::Disetujui, $cuti->refresh()->status);
        $this->assertSame($this->pemilik->id, $cuti->persetujuan->first()->diputuskan_oleh);
    }

    public function test_penolakan_mengakhiri_pengajuan_dan_mengembalikan_kuota(): void
    {
        $jenis = JenisCuti::factory()->kuota(3)->alur([RoleName::Admin, RoleName::SuperAdmin])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->actingAs($this->admin)
            ->post(route('leaves.reject', $cuti), ['catatan' => 'Stok opname minggu itu'])
            ->assertRedirect(route('leaves.show', $cuti))
            ->assertSessionHas('success', 'Pengajuan CT-00001 ditolak.');

        $cuti->refresh();
        $this->assertSame(StatusCuti::Ditolak, $cuti->status);
        $this->assertNull($cuti->tahap_aktif);
        $this->assertSame(['ditolak', 'menunggu'], $cuti->persetujuan->map(fn ($p) => $p->status->value)->all());
        $this->assertSame('Stok opname minggu itu', $cuti->persetujuan->first()->catatan);
        $this->setujui($this->pemilik, $cuti)->assertSessionHas('error', 'Pengajuan CT-00001 sudah ditolak.');

        $this->ajukan($this->kasir, $jenis)->assertSessionMissing('error');
        $this->assertSame(2, Cuti::count());
    }

    public function test_penolakan_wajib_disertai_alasan(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->actingAs($this->admin)
            ->post(route('leaves.reject', $cuti), ['catatan' => ''])
            ->assertSessionHasErrors(['catatan' => 'Tuliskan alasan penolakan supaya pemohon tahu.']);

        $this->assertSame(StatusCuti::Menunggu, $cuti->refresh()->status);
    }

    public function test_tanggal_yang_sudah_punya_absensi_tidak_ditimpa_saat_disetujui(): void
    {
        Absensi::factory()->for($this->kasir)->create(['tanggal' => '2026-11-11', 'clock_in' => '2026-11-11 08:00:00']);
        $jenis = JenisCuti::factory()->create();

        $this->ajukan($this->kasir, $jenis);

        $this->assertSame([['2026-11-10', 'cuti'], ['2026-11-11', 'hadir'], ['2026-11-12', 'cuti']], $this->absensiKasir());
    }

    public function test_pegawai_tidak_dapat_absen_masuk_di_hari_cuti(): void
    {
        $jenis = JenisCuti::factory()->create();
        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-02', 'tanggal_selesai' => '2026-11-02']);

        $this->actingAs($this->kasir)
            ->post(route('attendances.clock-in'))
            ->assertSessionHas('error', 'Hari ini Anda sudah tercatat Cuti. Hubungi admin bila perlu diubah.');

        $this->assertNull(Absensi::sole()->clock_in);
    }

    // ---------------- Pembatalan ----------------

    public function test_pemohon_dapat_membatalkan_pengajuan_yang_masih_menunggu(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->actingAs($this->kasir)
            ->post(route('leaves.cancel', $cuti))
            ->assertSessionHas('success', 'Pengajuan CT-00001 dibatalkan. Kuotanya dikembalikan.');

        $this->assertSame(StatusCuti::Dibatalkan, $cuti->refresh()->status);
        $this->assertNull($cuti->tahap_aktif);
        $this->setujui($this->admin, $cuti)->assertForbidden();
    }

    public function test_pengajuan_yang_sudah_disetujui_tidak_dapat_dibatalkan(): void
    {
        $jenis = JenisCuti::factory()->create();
        $this->ajukan($this->kasir, $jenis);
        $cuti = Cuti::sole();

        $this->actingAs($this->kasir)->post(route('leaves.cancel', $cuti))->assertForbidden();

        $this->assertSame(StatusCuti::Disetujui, $cuti->refresh()->status);
    }

    public function test_pegawai_lain_tidak_dapat_membatalkan_pengajuan(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis);

        $this->actingAs($this->buatPengguna(RoleName::Kasir))->post(route('leaves.cancel', Cuti::sole()))->assertForbidden();

        $this->assertSame(StatusCuti::Menunggu, Cuti::sole()->status);
    }

    // ---------------- Daftar dan detail ----------------

    public function test_tab_perlu_persetujuan_hanya_berisi_tahap_aktif_untuk_saya(): void
    {
        $penyelia = $this->buatPengguna(RoleName::Kasir);
        $untukAdmin = JenisCuti::factory()->alur([RoleName::Admin, RoleName::SuperAdmin])->create();
        $untukPenyelia = JenisCuti::factory()->alur([$penyelia])->create();
        $this->ajukan($this->kasir, $untukAdmin);
        $this->ajukan($this->buatPengguna(RoleName::Kasir), $untukPenyelia);
        $this->ajukan($this->admin, $untukAdmin, ['tanggal_mulai' => '2026-11-20', 'tanggal_selesai' => '2026-11-20']);

        $nomorUntuk = fn (User $user) => $this->actingAs($user)
            ->get(route('leaves.index', ['tab' => 'persetujuan']))
            ->assertOk()
            ->viewData('cuti')->pluck('nomor')->all();

        // Pengajuan admin sendiri (CT-00003) tidak muncul di tab admin.
        $this->assertSame(['CT-00001'], $nomorUntuk($this->admin));
        $this->assertSame(['CT-00002'], $nomorUntuk($penyelia));

        $this->setujui($this->admin, Cuti::firstWhere('nomor', 'CT-00001'));

        // CT-00001 pindah ke tahap 2 (Super Admin). CT-00003 masih di tahap Admin, jadi tidak muncul di tab pemilik.
        $this->assertSame([], $nomorUntuk($this->admin));
        $this->assertSame(['CT-00001'], $nomorUntuk($this->pemilik));
    }

    public function test_kasir_melihat_sisa_kuota_dan_pengajuannya_sendiri(): void
    {
        $jenis = JenisCuti::factory()->kuota(12)->alur([RoleName::Admin])->create(['nama' => 'Cuti Tahunan']);
        $this->ajukan($this->kasir, $jenis);
        $this->ajukan($this->buatPengguna(RoleName::Kasir), $jenis);

        $this->actingAs($this->kasir)
            ->get(route('leaves.index'))
            ->assertOk()
            ->assertViewHas('kuota', fn ($kuota) => $kuota->all() === [['nama' => 'Cuti Tahunan', 'kuota' => 12, 'terpakai' => 3]])
            ->assertViewHas('cuti', fn ($cuti) => $cuti->pluck('nomor')->all() === ['CT-00001'])
            ->assertSeeText('dari 12 hari tersisa');
    }

    public function test_kasir_tidak_dapat_membuka_semua_pengajuan_atau_pengajuan_orang_lain(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->buatPengguna(RoleName::Kasir), $jenis);

        $this->actingAs($this->kasir)->get(route('leaves.index', ['tab' => 'semua']))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('leaves.show', Cuti::sole()))->assertForbidden();
    }

    public function test_approver_membuka_detail_dan_melihat_form_keputusan(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin, RoleName::SuperAdmin])->create();
        $this->ajukan($this->kasir, $jenis);

        $this->actingAs($this->admin)
            ->get(route('leaves.show', Cuti::sole()))
            ->assertOk()
            ->assertViewHas('bisaMemutuskan', true)
            ->assertSeeText(['Kasir Satu', 'Keputusan Anda · Tahap 1 dari 2', 'Setujui & teruskan']);
    }

    public function test_detail_menampilkan_riwayat_keputusan_dan_catatan_absensi(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis, ['tanggal_mulai' => '2026-11-10', 'tanggal_selesai' => '2026-11-10']);
        $cuti = Cuti::sole();
        $this->setujui($this->admin, $cuti, 'Selamat berlibur');

        $this->actingAs($this->kasir)
            ->get(route('leaves.show', $cuti))
            ->assertOk()
            ->assertViewHas('bisaBatal', false)
            ->assertSeeTextInOrder(['Disetujui oleh Admin Toko', 'Selamat berlibur'])
            ->assertSeeTextInOrder(['Tercatat di absensi', 'Selasa, 10-11-2026', 'Cuti']);
    }

    public function test_alasan_cuti_ditampilkan_dengan_aman(): void
    {
        $jenis = JenisCuti::factory()->alur([RoleName::Admin])->create();
        $this->ajukan($this->kasir, $jenis, ['alasan' => '<script>alert("x")</script>']);

        $this->actingAs($this->kasir)
            ->get(route('leaves.show', Cuti::sole()))
            ->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_menu_jadwal_dan_cuti_tampil_untuk_kasir_tetapi_jenis_cuti_tidak(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('schedules.index'))
            ->assertSee(route('leaves.index'))
            ->assertDontSee(route('leave-types.index'))
            ->assertDontSee(route('shifts.index'));
    }
}
