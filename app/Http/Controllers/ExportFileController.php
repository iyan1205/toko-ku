<?php

namespace App\Http\Controllers;

use App\Models\ExportFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ekspor Saya: berkas ekspor besar yang dibuat di latar belakang. Hanya milik sendiri. */
class ExportFileController extends Controller
{
    public function index(Request $request): View
    {
        return view('exports.mine', [
            'exports' => ExportFile::query()->where('user_id', $request->user()->getKey())->latest('id')->paginate(20),
        ]);
    }

    public function download(Request $request, ExportFile $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->getKey(), 404);
        abort_unless($export->isReady() && $export->path && Storage::disk($export->disk)->exists($export->path), 410, 'Berkas ekspor sudah kedaluwarsa. Ekspor ulang dari halaman daftarnya.');

        return Storage::disk($export->disk)->download($export->path, $export->file_name);
    }
}
