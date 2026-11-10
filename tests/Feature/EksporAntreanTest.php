<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exports\ListExport;
use App\Exports\Purchasing\SuppliersExport;
use App\Jobs\GenerateListExport;
use App\Models\ExportFile;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\ExportReady;
use App\Support\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

/** Ekspor data besar lewat queue (bila worker hidup), berkas di Ekspor Saya, dan halaman Sistem. */
class EksporAntreanTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->admin = $this->buatPengguna(RoleName::Admin);
    }

    private function banyakSupplier(): void
    {
        Supplier::factory()->count(ListExport::QUEUE_THRESHOLD + 1)->create();
    }

    public function test_tanpa_worker_ekspor_besar_tetap_langsung_diunduh(): void
    {
        $this->banyakSupplier();
        config(['queue.default' => 'database']);
        Queue::fake();

        $this->actingAs($this->admin)->get(route('suppliers.export'))->assertOk()->assertDownload();

        Queue::assertNothingPushed();
        $this->assertSame(0, ExportFile::count());
    }

    public function test_dengan_worker_ekspor_besar_dibuat_di_latar_belakang_lalu_diberi_notifikasi(): void
    {
        $this->banyakSupplier();
        config(['queue.default' => 'database']);
        QueueHealth::workerBeat();
        Queue::fake();

        $this->actingAs($this->admin)->from(route('suppliers.index'))->get(route('suppliers.export', ['sort' => 'code', 'dir' => 'desc']))
            ->assertRedirect(route('suppliers.index'))
            ->assertSessionHas('success');

        $ekspor = ExportFile::sole();
        $this->assertSame([ExportFile::QUEUED, SuppliersExport::class, 'xlsx'], [$ekspor->status, $ekspor->export_class, $ekspor->format]);
        $this->assertSame(['sort' => 'code', 'dir' => 'desc'], $ekspor->filters);
        Queue::assertPushed(GenerateListExport::class, fn (GenerateListExport $job) => $job->export->is($ekspor));

        // Jalankan job-nya.
        Storage::fake('local');
        Notification::fake();
        app()->call([new GenerateListExport($ekspor), 'handle']);

        $ekspor->refresh();
        $this->assertTrue($ekspor->isReady());
        Storage::disk('local')->assertExists($ekspor->path);
        Notification::assertSentTo($this->admin, ExportReady::class);

        $this->get(route('exports.index'))->assertOk()->assertSee('Siap diunduh');
        $this->get(route('exports.download', $ekspor))->assertOk()->assertDownload($ekspor->file_name);
        $this->actingAs($this->buatPengguna(RoleName::Admin))->get(route('exports.download', $ekspor))->assertNotFound();
    }

    public function test_job_gagal_mencatat_kesalahan_dan_memberi_tahu(): void
    {
        Notification::fake();
        $ekspor = new ExportFile;
        $ekspor->forceFill(['user_id' => $this->admin->id, 'title' => 'Daftar Supplier', 'export_class' => SuppliersExport::class, 'format' => 'xlsx', 'file_name' => 'x.xlsx', 'status' => ExportFile::PROCESSING])->save();

        (new GenerateListExport($ekspor))->failed(new \RuntimeException('Memori habis'));

        $this->assertSame([ExportFile::FAILED, 'Memori habis'], [$ekspor->fresh()->status, $ekspor->fresh()->error]);
        Notification::assertSentTo($this->admin, ExportReady::class, fn (ExportReady $n) => str_starts_with($n->title(), 'Ekspor gagal'));
    }

    public function test_halaman_sistem_menampilkan_status_scheduler_dan_worker(): void
    {
        $pemilik = $this->buatPengguna(RoleName::Pemilik);

        $this->actingAs($pemilik)->get(route('settings.system.index'))->assertOk()
            ->assertSee('Scheduler (jadwal tugas)')
            ->assertSee('Tidak berjalan')
            ->assertSee('artisan schedule:run');

        QueueHealth::schedulerBeat();
        $this->get(route('settings.system.index'))->assertOk()->assertDontSee('artisan schedule:run');

        $this->actingAs($this->admin)->get(route('settings.system.index'))->assertForbidden();
    }
}
