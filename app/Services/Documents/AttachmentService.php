<?php

namespace App\Services\Documents;

use App\Exceptions\AturanBisnisException;
use App\Models\Attachment;
use App\Models\Concerns\HasAttachments;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Simpan, buka, dan hapus lampiran dokumen. Berkas ditaruh di disk privat
 * attachments/{jenis}/{tahun}/{bulan}/{acak}.{ekstensi}; nama asli disimpan di database.
 */
class AttachmentService
{
    /** Ukuran maksimal (KB): terkecil dari config dan batas upload PHP di server. */
    public function maxKilobytes(): int
    {
        $php = min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size'));

        return (int) min((int) config('attachments.max_kb', 10240), $php > 0 ? floor($php / 1024) : PHP_INT_MAX);
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return config('attachments.mimes', []);
    }

    /** Dokumen terdaftar di config/attachments.php dan memakai HasAttachments. */
    public function supports(Model $document): bool
    {
        return in_array(HasAttachments::class, class_uses_recursive($document), true)
            && config("attachments.types.{$document->getMorphClass()}") !== null;
    }

    public function store(Model $document, UploadedFile $file, User $user, ?string $description = null): Attachment
    {
        if (! $this->supports($document)) {
            throw new AturanBisnisException('Dokumen ini tidak bisa diberi lampiran.');
        }

        $disk = config('attachments.disk', 'local');
        // Ekstensi dari isi berkas (bukan dari nama kiriman pengguna); nama asli tetap disimpan di database.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $folder = 'attachments/'.$document->getMorphClass().'/'.now()->format('Y/m');
        $path = $file->storeAs($folder, Str::uuid().'.'.$extension, $disk);

        $attachment = $document->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 255) : null,
            'uploaded_by' => $user->getKey(),
        ]);

        activity('dokumen')->performedOn($document)->causedBy($user)->event('attachment_added')
            ->withProperties(['berkas' => $attachment->original_name, 'ukuran' => $attachment->humanSize()])
            ->log("Lampiran {$attachment->original_name} ditambahkan");

        return $attachment;
    }

    public function delete(Attachment $attachment, User $user): void
    {
        $document = $attachment->attachable;
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        if ($document) {
            activity('dokumen')->performedOn($document)->causedBy($user)->event('attachment_deleted')
                ->withProperties(['berkas' => $attachment->original_name])
                ->log("Lampiran {$attachment->original_name} dihapus");
        }
    }

    public function deleteAllFor(Model $document): void
    {
        Attachment::query()->where('attachable_type', $document->getMorphClass())->where('attachable_id', $document->getKey())->get()
            ->each(function (Attachment $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->delete();
            });
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
