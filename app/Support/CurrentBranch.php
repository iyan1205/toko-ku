<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Cabang yang sedang dipilih pengguna di topbar (disimpan di session), dan cabang yang boleh
 * diaksesnya. Dipakai BranchScope untuk menyaring data transaksi.
 *
 *   - Pengguna dengan users.all_branches (bawaan) atau Super Admin boleh mengakses semua cabang;
 *     selain itu hanya cabang di tabel branch_user.
 *   - Pilihan "Semua cabang" tersedia bila pengguna boleh mengakses lebih dari satu cabang.
 *   - Bila belum memilih, atau pilihannya sudah nonaktif/di luar akses, dipakai cabang utama
 *     (atau cabang pertama yang boleh diakses).
 *   - Selama toko baru punya satu cabang, data tidak disaring sama sekali.
 *
 * Didaftarkan scoped di AppServiceProvider. Hasil hitung diingat per request.
 */
final class CurrentBranch
{
    public const SESSION_KEY = 'branch_id';

    public const ALL = 'all';

    /** @var array<string, mixed> */
    private array $memo = [];

    private ?int $memoRequest = null;

    /** @var list<int>|null */
    private ?array $override = null;

    private bool $overridden = false;

    /** @return Collection<int, Branch> cabang aktif yang boleh diakses pengguna login, cabang utama paling atas */
    public function options(): Collection
    {
        return $this->remember('options:'.$this->userKey(), function () {
            $ids = ($user = $this->user()) ? $this->accessibleIds($user) : null;

            return Branch::query()->active()->ordered()
                ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
                ->get();
        });
    }

    /** Cabang yang dipilih, atau null bila "Semua cabang" (atau belum ada cabang yang bisa diakses). */
    public function get(): ?Branch
    {
        if ($this->isAll()) {
            return null;
        }

        $options = $this->options();

        return $options->firstWhere('id', (int) session(self::SESSION_KEY))
            ?? $options->firstWhere('is_default', true)
            ?? $options->first();
    }

    public function isAll(): bool
    {
        return session(self::SESSION_KEY) === self::ALL && $this->canChooseAll();
    }

    public function canChooseAll(): bool
    {
        return $this->options()->count() > 1;
    }

    /** Pilih cabang; null = "Semua cabang". */
    public function set(?Branch $branch): void
    {
        session([self::SESSION_KEY => $branch?->getKey() ?? self::ALL]);
    }

    /**
     * Id cabang untuk menyaring data, atau null bila tidak perlu disaring
     * (tanpa login, satu cabang saja, atau "Semua cabang" oleh pengguna yang boleh semua).
     *
     * @return list<int>|null
     */
    public function scopeIds(): ?array
    {
        if ($this->overridden) {
            return $this->override;
        }

        $user = $this->user();

        if ($user === null || $this->branchCount() <= 1) {
            return null;
        }

        return $this->remember('scope:'.$user->getKey().':'.json_encode(session(self::SESSION_KEY)), function () use ($user) {
            if ($this->isAll()) {
                return $this->accessibleIds($user);
            }

            $branch = $this->get();

            return $branch ? [$branch->getKey()] : [];
        });
    }

    /** Dokumen baru boleh masuk ke cabang ini (terlihat di pilihan cabang saat ini). */
    public function allows(int $branchId): bool
    {
        $ids = $this->scopeIds();

        return $ids === null || in_array($branchId, $ids, true);
    }

    /** Cabang untuk dokumen baru yang tidak punya gudang/dokumen asal. */
    public function forNewDocument(): Branch
    {
        return $this->get()
            ?? $this->options()->firstWhere('is_default', true)
            ?? $this->options()->first()
            ?? Branch::query()->where('is_default', true)->firstOrFail();
    }

    public function companyIdOf(int $branchId): int
    {
        return $this->remember("company:{$branchId}", fn () => (int) (Branch::withTrashed()->whereKey($branchId)->value('company_id')
            ?? Company::default()->getKey()));
    }

    /**
     * Id cabang yang boleh diakses, atau null bila semua cabang.
     *
     * @return list<int>|null
     */
    public function accessibleIds(User $user): ?array
    {
        if (($user->all_branches ?? true) || $user->isSuperAdmin()) {
            return null;
        }

        return $this->remember('access:'.$user->getKey(), fn () => $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Saring data untuk proses tanpa session (job ekspor): pakai id cabang milik pengguna yang memintanya.
     * null = tanpa saringan. Panggil restrictTo(null) lalu forgetRestriction() setelah selesai.
     *
     * @param  list<int>|null  $ids
     */
    public function restrictTo(?array $ids): void
    {
        $this->override = $ids;
        $this->overridden = true;
    }

    public function forgetRestriction(): void
    {
        $this->override = null;
        $this->overridden = false;
    }

    /** Lupakan hasil hitung (setelah cabang atau akses pengguna diubah). */
    public function flush(): void
    {
        $this->memo = [];
    }

    private function branchCount(): int
    {
        return $this->remember('count', fn () => Branch::withTrashed()->count());
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function userKey(): string
    {
        return (string) ($this->user()?->getKey() ?? 'tamu');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(string $key, callable $callback): mixed
    {
        // Satu instance bisa melayani beberapa request (test HTTP): hasil hitung berlaku per request.
        $request = spl_object_id(request());
        if ($this->memoRequest !== $request) {
            $this->memo = [];
            $this->memoRequest = $request;
        }

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }
}
