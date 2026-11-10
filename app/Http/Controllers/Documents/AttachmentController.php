<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Documents\AttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lampiran dokumen. Izin mengikuti dokumennya (config/attachments.php): 'view' untuk membuka,
 * 'update' untuk menambah dan menghapus. Dokumen dicari dengan saringan cabang aktif, jadi
 * lampiran dokumen cabang lain tidak bisa dibuka.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(Request $request, string $type, int $id): JsonResponse|RedirectResponse
    {
        $document = $this->document($type, $id);
        $this->authorizeFor($request, $document, 'update');

        $request->validate([
            'file' => ['required', 'file', 'max:'.$this->attachments->maxKilobytes(), 'mimes:'.implode(',', $this->attachments->allowedExtensions())],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'file.max' => 'Ukuran berkas maksimal '.$this->maxLabel().'.',
            'file.mimes' => 'Jenis berkas tidak didukung. Pakai '.implode(', ', $this->attachments->allowedExtensions()).'.',
            'file.uploaded' => 'Berkas gagal diunggah. Ukuran maksimal '.$this->maxLabel().'.',
        ], ['file' => 'berkas']);

        $attachment = $this->attachments->store($document, $request->file('file'), $request->user(), $request->input('description'));

        return $request->expectsJson()
            ? response()->json(['item' => $attachment->load('uploader:id,name')->toPanelArray(true)], 201)
            : back()->with('success', "Lampiran {$attachment->original_name} ditambahkan.");
    }

    /** Pratinjau di browser (gambar/PDF); jenis lain diunduh. */
    public function show(Request $request, Attachment $attachment): Response
    {
        return $this->respond($request, $attachment, $attachment->isPreviewable() ? 'inline' : 'attachment');
    }

    public function download(Request $request, Attachment $attachment): Response
    {
        return $this->respond($request, $attachment, 'attachment');
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse|RedirectResponse
    {
        $this->authorizeFor($request, $this->document($attachment->attachable_type, $attachment->attachable_id), 'update');
        $this->attachments->delete($attachment, $request->user());

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', "Lampiran {$attachment->original_name} dihapus.");
    }

    private function respond(Request $request, Attachment $attachment, string $disposition): Response
    {
        $this->authorizeFor($request, $this->document($attachment->attachable_type, $attachment->attachable_id), 'view');
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ], $disposition);
    }

    private function document(string $type, int|string $id): Model
    {
        $class = config("attachments.types.{$type}") !== null ? Relation::getMorphedModel($type) : null;
        abort_if($class === null, 404);

        return $class::query()->findOrFail($id);
    }

    private function authorizeFor(Request $request, Model $document, string $action): void
    {
        $permission = method_exists($document, 'attachmentPermission') ? $document->attachmentPermission($action) : null;
        abort_unless($permission !== null && $request->user()->can($permission), 403);
    }

    private function maxLabel(): string
    {
        $kb = $this->attachments->maxKilobytes();

        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1, ',', '.'), '0'), ',').' MB' : "{$kb} KB";
    }
}
