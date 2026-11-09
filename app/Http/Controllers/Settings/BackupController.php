<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar, buat, dan unduh cadangan database. Isi cadangan mencakup seluruh data termasuk
 * akun pengguna, jadi izin settings.backup.* sebaiknya hanya untuk pemilik.
 * Izin dipasang di routes/modules/settings.php.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    public function index(): View
    {
        $driver = config('database.connections.'.config('database.default').'.driver');

        return view('settings.backups', [
            'backups' => $this->backups->all(),
            'supported' => in_array($driver, ['mysql', 'mariadb'], true),
            'keep' => DatabaseBackupService::KEEP,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $this->backups->create();

        activity('pengaturan')->causedBy($request->user())->event('backup_created')
            ->withProperties(['berkas' => $name, 'sumber' => 'manual'])
            ->log("Backup database {$name} dibuat");

        return redirect()->route('settings.backups.index')->with('success', "Backup {$name} berhasil dibuat.");
    }

    public function download(Request $request, string $name): StreamedResponse
    {
        abort_unless($this->backups->isBackupName($name) && Storage::disk('local')->exists($this->backups->path($name)), 404);

        activity('pengaturan')->causedBy($request->user())->event('backup_downloaded')
            ->withProperties(['berkas' => $name])
            ->log("Backup database {$name} diunduh");

        return Storage::disk('local')->download($this->backups->path($name), $name, ['Content-Type' => 'application/gzip']);
    }
}
