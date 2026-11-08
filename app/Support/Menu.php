<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Menu sidebar dari config/menu.php (grup -> menu -> submenu).
 *
 * Sebuah item tampil bila: statusnya bukan hidden, pengguna punya salah satu izinnya,
 * dan (untuk item enabled) route-nya terdaftar. Menu induk tampil bila minimal satu
 * submenunya tampil. Item coming_soon diarahkan ke halaman "Segera Hadir".
 */
final class Menu
{
    public const ENABLED = 'enabled';

    public const COMING_SOON = 'coming_soon';

    public const HIDDEN = 'hidden';

    /**
     * Menu yang boleh dilihat pengguna, lengkap dengan url, status efektif, dan penanda aktif.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function tree(?User $user): array
    {
        $groups = [];

        foreach (config('menu', []) as $group) {
            $items = [];

            foreach ($group['items'] ?? [] as $item) {
                if ($resolved = self::resolve($item, $user, self::ENABLED)) {
                    $items[] = $resolved;
                }
            }

            if ($items !== []) {
                $groups[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * Semua halaman aktif (enabled) yang boleh dibuka pengguna, sebagai daftar datar
     * dengan ikon menu induknya. Dipakai untuk pintasan di dashboard.
     *
     * @return Collection<int, array{key: string, label: string, url: string, icon: ?string, parent: ?string}>
     */
    public static function links(?User $user): Collection
    {
        return collect(self::tree($user))
            ->flatMap(fn (array $group) => $group['items'])
            ->flatMap(fn (array $item) => isset($item['children'])
                ? collect($item['children'])->map(fn (array $child) => [...$child, 'icon' => $item['icon'] ?? null, 'parent' => $item['label']])
                : [[...$item, 'parent' => null]])
            ->where('status', self::ENABLED)
            ->map(fn (array $item) => [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => $item['url'],
                'icon' => $item['icon'] ?? null,
                'parent' => $item['parent'],
            ])
            ->values();
    }

    /**
     * Menu yang sedang aktif di hasil tree(): item (halaman) dan induknya, untuk breadcrumb
     * dan deskripsi halaman. Null bila halaman saat ini tidak ada di menu.
     *
     * @param  list<array{label: string, items: list<array<string, mixed>>}>  $tree
     * @return array{item: array<string, mixed>, parent: ?array<string, mixed>}|null
     */
    public static function current(array $tree): ?array
    {
        foreach ($tree as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['children'] ?? [] as $child) {
                    if ($child['is_active']) {
                        return ['item' => $child, 'parent' => $item];
                    }
                }

                if (! isset($item['children']) && $item['is_active']) {
                    return ['item' => $item, 'parent' => null];
                }
            }
        }

        return null;
    }

    /**
     * Cari item berdasarkan key di config (tanpa memeriksa izin), beserta induk dan status efektifnya.
     *
     * @return array{item: array<string, mixed>, parent: ?array<string, mixed>, status: string}|null
     */
    public static function find(string $key): ?array
    {
        foreach (config('menu', []) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (($item['key'] ?? null) === $key) {
                    return ['item' => $item, 'parent' => null, 'status' => $item['status'] ?? self::ENABLED];
                }

                foreach ($item['children'] ?? [] as $child) {
                    if (($child['key'] ?? null) === $key) {
                        $parentStatus = $item['status'] ?? self::ENABLED;

                        return [
                            'item' => $child,
                            'parent' => $item,
                            'status' => $parentStatus === self::ENABLED ? ($child['status'] ?? self::ENABLED) : $parentStatus,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /** @param  string|list<string>|null  $permission  null = semua pengguna login; array = salah satu cukup */
    public static function allows(string|array|null $permission, ?User $user): bool
    {
        if ($permission === null) {
            return $user !== null;
        }

        return $user !== null && $user->canAny((array) $permission);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function resolve(array $item, ?User $user, string $parentStatus): ?array
    {
        $status = $parentStatus === self::ENABLED ? ($item['status'] ?? self::ENABLED) : $parentStatus;

        if ($status === self::HIDDEN || ! self::allows($item['permission'] ?? null, $user)) {
            return null;
        }

        if (isset($item['children'])) {
            $children = array_values(array_filter(array_map(
                fn (array $child) => self::resolve($child, $user, $status),
                $item['children'],
            )));

            if ($children === []) {
                return null;
            }

            $allComingSoon = collect($children)->every(fn (array $child) => $child['status'] === self::COMING_SOON);

            return [
                ...$item,
                'children' => $children,
                'status' => $allComingSoon ? self::COMING_SOON : self::ENABLED,
                'url' => null,
                'is_active' => collect($children)->contains('is_active', true),
            ];
        }

        if ($status === self::COMING_SOON) {
            return [
                ...$item,
                'status' => $status,
                'url' => route('coming-soon', $item['key']),
                'is_active' => request()->routeIs('coming-soon') && request()->route('key') === $item['key'],
            ];
        }

        if (! Route::has($item['route'] ?? '')) {
            return null;
        }

        return [
            ...$item,
            'status' => $status,
            'url' => route($item['route']),
            'is_active' => request()->routeIs(...(array) ($item['active'] ?? $item['route'])),
            'is_index' => request()->routeIs($item['route']),
        ];
    }
}
