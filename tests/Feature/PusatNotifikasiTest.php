<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\ApprovalRequested;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Lonceng di topbar, tandai dibaca, halaman notifikasi, preferensi kanal, dan email lewat queue. */
class PusatNotifikasiTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->kasir = $this->buatPengguna(RoleName::Kasir, ['name' => 'Sari Kasir']);
    }

    public function test_lonceng_menampilkan_jumlah_belum_dibaca_dan_isi_terbaru(): void
    {
        $this->kasir->notify(new NotifikasiUji('Retur disetujui', '/penjualan'));
        $this->kasir->notify(new NotifikasiUji('Ekspor siap', '/notifikasi/semua'));
        $lain = $this->buatPengguna(RoleName::Kasir);
        $lain->notify(new NotifikasiUji('Milik orang lain', '/dashboard'));

        $this->actingAs($this->kasir)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Notifikasi: 2 notifikasi belum dibaca"', false);

        $this->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.read', false);

        $titles = collect($this->getJson(route('notifications.feed'))->json('items'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Ekspor siap', 'Retur disetujui'], $titles);
    }

    public function test_membuka_notifikasi_menandai_dibaca_dan_hanya_milik_sendiri(): void
    {
        $this->kasir->notify(new NotifikasiUji('Retur disetujui', url('/penjualan')));
        $milikKasir = $this->kasir->notifications()->sole();
        $lain = $this->buatPengguna(RoleName::Kasir);
        $lain->notify(new NotifikasiUji('Milik orang lain', '/dashboard'));

        $this->actingAs($this->kasir)->get(route('notifications.open', $milikKasir->id))->assertRedirect(url('/penjualan'));
        $this->assertNotNull($milikKasir->fresh()->read_at);

        $this->get(route('notifications.open', $lain->notifications()->sole()->id))->assertNotFound();
        $this->post(route('notifications.read', $lain->notifications()->sole()->id))->assertNotFound();
        $this->assertNull($lain->notifications()->sole()->read_at);
    }

    public function test_tautan_luar_tidak_diikuti(): void
    {
        $this->kasir->notify(new NotifikasiUji('Aneh', 'https://contoh-jahat.test/login'));

        $this->actingAs($this->kasir)
            ->get(route('notifications.open', $this->kasir->notifications()->sole()->id))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_tandai_semua_dibaca_dan_halaman_semua_notifikasi(): void
    {
        $this->kasir->notify(new NotifikasiUji('Satu', '/'));
        $this->kasir->notify(new NotifikasiUji('Dua', '/'));

        $this->actingAs($this->kasir)->get(route('notifications.index', ['status' => 'belum-dibaca']))
            ->assertOk()->assertSee('Satu')->assertSee('Dua')->assertSee('Belum dibaca (2)');

        $this->postJson(route('notifications.read-all'))->assertOk();
        $this->assertSame(0, $this->kasir->unreadNotifications()->count());
        $this->get(route('notifications.index', ['status' => 'belum-dibaca']))->assertDontSee('Satu');
    }

    public function test_preferensi_kanal_per_pengguna(): void
    {
        $prefs = app(NotificationPreferenceService::class);

        // Bawaan dari config/notification-types.php.
        $this->assertSame(['database', 'mail'], $prefs->channels($this->kasir, ApprovalRequested::type()));
        $this->assertSame(['database'], $prefs->channels($this->kasir, 'export.ready'));

        $this->actingAs($this->kasir)->get(route('profile.edit'))->assertOk()->assertSee('Permintaan persetujuan');
        $this->put(route('profile.notifications'), ['preferences' => [
            'approval.requested' => ['database' => '1', 'mail' => '0'],
            'approval.decided' => ['database' => '0', 'mail' => '0'],
            'export.ready' => ['database' => '1', 'mail' => '1'],
        ]])->assertRedirect(route('profile.edit'));

        $this->assertSame(['database'], $prefs->channels($this->kasir, 'approval.requested'));
        $this->assertSame([], $prefs->channels($this->kasir, 'approval.decided'));
        $this->assertSame(['database', 'mail'], $prefs->channels($this->kasir, 'export.ready'));
        $this->assertSame(3, NotificationPreference::where('user_id', $this->kasir->id)->count());

        // Semua kanal dimatikan: tidak ada yang tersimpan di lonceng.
        $this->kasir->notify(new NotifikasiUji('Tidak terkirim', '/', 'approval.decided'));
        $this->assertSame(0, $this->kasir->notifications()->count());
    }

    public function test_lonceng_langsung_dan_email_lewat_queue(): void
    {
        $notif = new NotifikasiUji('Ada persetujuan', '/persetujuan', 'approval.requested');

        $this->assertSame(['database' => 'sync'], $notif->viaConnections());
        $this->assertInstanceOf(ShouldQueue::class, $notif);

        Queue::fake();
        $this->kasir->notify($notif);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === ['mail']);

        $mail = $notif->toMail($this->kasir);
        $this->assertSame('Ada persetujuan', $mail->subject);
        $this->assertSame('Halo, Sari Kasir!', $mail->greeting);
    }
}

/** Notifikasi sederhana untuk test. */
class NotifikasiUji extends AppNotification
{
    public function __construct(public string $judul, public string $tautan, public string $jenis = 'export.ready') {}

    public static function type(): string
    {
        return 'export.ready';
    }

    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->channels($notifiable, $this->jenis);
    }

    public function title(): string
    {
        return $this->judul;
    }

    public function body(): string
    {
        return 'Isi notifikasi '.$this->judul;
    }

    public function url(): ?string
    {
        return $this->tautan;
    }
}
