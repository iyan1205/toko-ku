<?php

namespace App\Services\Sdm;

use App\Exceptions\AturanBisnisException;
use App\Models\Employee;
use App\Services\KodeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tambah, ubah, dan hapus karyawan. Kode otomatis KRY-0001 bila tidak diisi.
 * Keluarga, pendidikan, dan pengalaman disimpan ulang dari formulir (baris kosong diabaikan).
 * Foto disimpan di disk lokal (bukan publik) dan dibuka lewat route employees.photo.
 */
class EmployeeService
{
    public const CODE_PREFIX = 'KRY-';

    public const PHOTO_DIR = 'employees/photos';

    public function __construct(private readonly KodeService $codes) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $data['code'] ??= $this->codes->berikutnya(Employee::class, 'code', self::CODE_PREFIX, 4);
            $employee = Employee::create($this->attributes($data));
            $this->syncChildren($employee, $data);
            $this->storePhoto($employee, $data);

            return $employee;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            // Kode yang dikosongkan saat mengubah tetap memakai kode lama.
            $data['code'] ??= $employee->code;
            if (isset($data['supervisor_id']) && in_array((int) $data['supervisor_id'], [$employee->id, ...$this->descendantIds($employee)], true)) {
                throw new AturanBisnisException('Atasan tidak boleh karyawan itu sendiri atau bawahannya.');
            }
            $employee->update($this->attributes($data));
            $this->syncChildren($employee, $data);
            $this->storePhoto($employee, $data);

            return $employee;
        });
    }

    /** Hapus lunak; tautan akun login dilepas supaya akun itu bisa dipakai karyawan lain. Bawahannya kehilangan atasan. */
    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            Employee::query()->where('supervisor_id', $employee->id)->update(['supervisor_id' => null]);
            $employee->forceFill(['user_id' => null])->save();
            $employee->delete();
        });
    }

    /**
     * Semua bawahan (langsung dan tidak langsung), untuk mencegah atasan melingkar.
     *
     * @return list<int>
     */
    public function descendantIds(Employee $employee): array
    {
        $children = Employee::query()->whereNotNull('supervisor_id')->pluck('supervisor_id', 'id')->all();
        $result = [];
        $queue = [$employee->id];
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach ($children as $id => $supervisor) {
                if ((int) $supervisor === $parent && ! in_array($id, $result, true)) {
                    $result[] = (int) $id;
                    $queue[] = (int) $id;
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return collect($data)->except(['family', 'educations', 'experiences', 'photo', 'remove_photo'])->all();
    }

    /** @param array<string, mixed> $data */
    private function syncChildren(Employee $employee, array $data): void
    {
        foreach (['family' => 'familyMembers', 'educations' => 'educations', 'experiences' => 'experiences'] as $key => $relation) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $employee->{$relation}()->delete();
            foreach ($data[$key] ?? [] as $row) {
                $employee->{$relation}()->create($row);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function storePhoto(Employee $employee, array $data): void
    {
        $old = $employee->photo_path;
        $photo = $data['photo'] ?? null;

        if ($photo instanceof UploadedFile) {
            $path = $photo->storeAs(self::PHOTO_DIR, $employee->id.'-'.bin2hex(random_bytes(6)).'.'.$photo->extension(), 'local');
            $employee->forceFill(['photo_path' => $path])->save();
        } elseif (! empty($data['remove_photo'])) {
            $employee->forceFill(['photo_path' => null])->save();
        } else {
            return;
        }

        if ($old !== null) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($old));
        }
    }
}
