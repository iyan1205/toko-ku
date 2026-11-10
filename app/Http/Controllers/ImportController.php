<?php

namespace App\Http\Controllers;

use App\Exports\ImportErrorsExport;
use App\Exports\ImportTemplateExport;
use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor massal untuk semua jenis di config/imports.php: templat, unggah → pratinjau per baris,
 * proses baris yang valid, dan laporan kesalahan. Route per jenis dijaga izin jenis itu
 * (routes/modules/imports.php); hanya pengunggah yang bisa membuka hasil impornya.
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function create(Request $request): View
    {
        $type = $this->type($request);

        return view('imports.create', [
            'type' => $type,
            'definition' => $this->imports->definition($type),
            'history' => ImportBatch::query()->where('type', $type)->where('user_id', $request->user()->getKey())
                ->latest('id')->limit(10)->get(),
            'maxKb' => (int) config('imports.max_kb', 5120),
        ]);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $type = $this->type($request);
        $definition = $this->imports->definition($type);

        return Excel::download(new ImportTemplateExport($definition), 'templat-impor-'.Str::slug($definition->title()).'.xlsx');
    }

    public function preview(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('imports.max_kb', 5120), 'mimes:'.implode(',', config('imports.mimes', ['xlsx', 'xls', 'csv']))],
        ], ['file.mimes' => 'Berkas harus Excel (.xlsx, .xls) atau CSV.'], ['file' => 'berkas']);

        $batch = $this->imports->preview($this->imports->definition($type), $request->file('file'), $request->user());

        return redirect()->route("imports.{$type}.show", $batch);
    }

    public function show(Request $request, ImportBatch $batch): View
    {
        $type = $this->type($request);
        $this->ensureOwner($request, $type, $batch);
        $filter = $request->validate(['tampil' => ['nullable', 'in:salah,valid']])['tampil'] ?? null;

        $rows = collect($batch->rows)
            ->when($filter === 'salah', fn ($c) => $c->filter(fn ($r) => $r['errors'] !== []))
            ->when($filter === 'valid', fn ($c) => $c->filter(fn ($r) => $r['errors'] === []))
            ->values();

        return view('imports.show', [
            'type' => $type,
            'definition' => $this->imports->definition($type),
            'batch' => $batch,
            'rows' => $rows->take(500),
            'hidden' => max(0, $rows->count() - 500),
            'filter' => $filter,
        ]);
    }

    public function commit(Request $request, ImportBatch $batch): RedirectResponse
    {
        $type = $this->type($request);
        $this->ensureOwner($request, $type, $batch);
        $definition = $this->imports->definition($type);
        $hasil = $this->imports->commit($definition, $batch, $request->user());

        return redirect()->route("imports.{$type}.show", $hasil)->with('success', sprintf(
            'Impor %s selesai: %d ditambahkan, %d diperbarui%s.',
            mb_strtolower($definition->title()), $hasil->created_count, $hasil->updated_count,
            $hasil->invalid_rows > 0 ? ", {$hasil->invalid_rows} baris salah dilewati" : '',
        ));
    }

    public function errors(Request $request, ImportBatch $batch): BinaryFileResponse
    {
        $type = $this->type($request);
        $this->ensureOwner($request, $type, $batch);
        $definition = $this->imports->definition($type);

        return Excel::download(new ImportErrorsExport($definition, $batch), 'kesalahan-impor-'.Str::slug($definition->title()).'-'.$batch->id.'.xlsx');
    }

    /** Jenis impor dari nama route imports.{jenis}.{aksi} (routes/modules/imports.php). */
    private function type(Request $request): string
    {
        return Str::between((string) $request->route()?->getName(), 'imports.', '.');
    }

    private function ensureOwner(Request $request, string $type, ImportBatch $batch): void
    {
        abort_unless($batch->type === $type && $batch->user_id === $request->user()->getKey(), 404);
    }
}
