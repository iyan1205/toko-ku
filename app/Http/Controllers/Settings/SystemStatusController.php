<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Documents\AttachmentService;
use App\Support\QueueHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengaturan → Sistem: apakah scheduler dan queue worker berjalan (docs/DEPLOY.md), antrean
 * job, job yang gagal (coba lagi / hapus), pengiriman email, dan batas unggah berkas.
 */
class SystemStatusController extends Controller
{
    public function index(AttachmentService $attachments): View
    {
        $connection = (string) config('queue.default');

        return view('settings.system', [
            'scheduler' => ['alive' => QueueHealth::schedulerAlive(), 'seen' => QueueHealth::schedulerLastSeen()],
            'worker' => ['alive' => QueueHealth::workerAlive(), 'seen' => QueueHealth::workerLastSeen()],
            'connection' => $connection,
            'pendingJobs' => $connection === 'database' ? DB::table(config('queue.connections.database.table', 'jobs'))->count() : null,
            'failedJobs' => DB::table(config('queue.failed.table', 'failed_jobs'))->latest('failed_at')->limit(10)->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']),
            'failedCount' => DB::table(config('queue.failed.table', 'failed_jobs'))->count(),
            'mailer' => (string) config('mail.default'),
            'uploadMb' => round($attachments->maxKilobytes() / 1024, 1),
            'basePath' => base_path(),
            'phpBinary' => PHP_BINDIR.'/php',
        ]);
    }

    public function retry(Request $request): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        activity('pengaturan')->causedBy($request->user())->event('queue_retried')->log('Semua job gagal dijalankan ulang');

        return back()->with('success', 'Semua job yang gagal dimasukkan kembali ke antrean.');
    }

    public function flush(Request $request): RedirectResponse
    {
        Artisan::call('queue:flush');

        activity('pengaturan')->causedBy($request->user())->event('queue_flushed')->log('Daftar job gagal dikosongkan');

        return back()->with('success', 'Daftar job yang gagal dikosongkan.');
    }
}
