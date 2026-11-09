<?php

namespace App\Services\Settings;

use App\Exceptions\AturanBisnisException;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Tambah, ubah, dan hapus cabang dengan aturan: selalu ada tepat satu cabang utama,
 * dan cabang utama tidak bisa dinonaktifkan atau dihapus.
 */
class BranchService
{
    /** @param array{code: string, name: string, address?: ?string, phone?: ?string, is_active?: bool, is_default?: bool} $data */
    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $branch = new Branch($data);
            $branch->save();

            if ($data['is_default'] ?? false) {
                $this->makeDefault($branch);
            }

            return $branch;
        });
    }

    /** @param array{code: string, name: string, address?: ?string, phone?: ?string, is_active?: bool, is_default?: bool} $data */
    public function update(Branch $branch, array $data): Branch
    {
        if ($branch->is_default && ! ($data['is_active'] ?? true)) {
            throw new AturanBisnisException('Cabang utama tidak bisa dinonaktifkan. Jadikan cabang lain sebagai cabang utama terlebih dahulu.');
        }

        return DB::transaction(function () use ($branch, $data) {
            $branch->fill($data)->save();

            if (($data['is_default'] ?? false) && ! $branch->is_default) {
                $this->makeDefault($branch);
            }

            return $branch;
        });
    }

    public function delete(Branch $branch): void
    {
        if ($branch->is_default) {
            throw new AturanBisnisException('Cabang utama tidak bisa dihapus. Jadikan cabang lain sebagai cabang utama terlebih dahulu.');
        }

        $branch->delete();
    }

    /** Jadikan cabang utama; cabang utama sebelumnya menjadi cabang biasa. Cabang utama selalu aktif. */
    private function makeDefault(Branch $branch): void
    {
        Branch::query()->whereKeyNot($branch->getKey())->where('is_default', true)->get()
            ->each(fn (Branch $other) => $other->forceFill(['is_default' => false])->save());

        $branch->forceFill(['is_default' => true, 'is_active' => true])->save();
    }
}
