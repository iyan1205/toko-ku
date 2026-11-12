<?php

namespace App\Services\Sdm;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documents\AttachmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Dokumen karyawan: metadata + berkas (lampiran), dan daftar yang akan/sudah kedaluwarsa. */
class EmployeeDocumentService
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /** @param array{type: string, name: string, number?: ?string, issued_at?: ?string, expires_at?: ?string, notes?: ?string, file?: ?UploadedFile} $data */
    public function add(Employee $employee, array $data, User $user): EmployeeDocument
    {
        return DB::transaction(function () use ($employee, $data, $user) {
            $document = $employee->documents()->make(collect($data)->except('file')->all());
            $document->created_by = $user->id;
            $document->save();
            if (($data['file'] ?? null) instanceof UploadedFile) {
                $this->attachments->store($document, $data['file'], $user);
            }

            activity('sdm')->performedOn($employee)->causedBy($user)->event('document_added')
                ->withProperties(['jenis' => $document->typeLabel(), 'berlaku_sampai' => $document->expires_at?->toDateString()])
                ->log("Dokumen {$document->typeLabel()} {$employee->name} ditambahkan");

            return $document;
        });
    }

    public function delete(EmployeeDocument $document, User $user): void
    {
        DB::transaction(function () use ($document, $user) {
            $this->attachments->deleteAllFor($document);
            $document->delete();
            activity('sdm')->performedOn($document->employee)->causedBy($user)->event('document_deleted')
                ->log("Dokumen {$document->typeLabel()} {$document->employee->name} dihapus");
        });
    }

    /**
     * Dokumen karyawan aktif yang kedaluwarsa dalam $days hari atau sudah lewat (sampai 30 hari).
     *
     * @return Builder<EmployeeDocument>
     */
    public function expiring(int $days): Builder
    {
        $today = CarbonImmutable::today();

        return EmployeeDocument::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->addDays($days)->toDateString())
            ->whereDate('expires_at', '>=', $today->subDays(30)->toDateString())
            ->whereHas('employee', fn (Builder $q) => $q->where('is_active', true))
            ->with('employee:id,code,name')
            ->orderBy('expires_at');
    }
}
