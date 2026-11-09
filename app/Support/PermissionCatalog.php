<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Pembaca katalog izin di config/permission-matrix.php.
 * Nama izin selalu "{modul}.{fitur}.{aksi}".
 */
final class PermissionCatalog
{
    /**
     * Semua nama izin, urut sesuai katalog.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::modules() as $module) {
            foreach ($module['features'] as $feature) {
                array_push($names, ...array_values($feature['permissions']));
            }
        }

        return $names;
    }

    /**
     * Katalog untuk matriks: modul -> fitur -> aksi => nama izin.
     *
     * @return array<string, array{label: string, features: array<string, array{label: string, planned: bool, permissions: array<string, string>}>}>
     */
    public static function modules(): array
    {
        $modules = [];

        foreach (config('permission-matrix.modules', []) as $moduleKey => $module) {
            $features = [];

            foreach ($module['features'] as $featureKey => $feature) {
                $features[$featureKey] = [
                    'label' => $feature['label'],
                    'planned' => (bool) ($feature['planned'] ?? false),
                    'permissions' => collect($feature['actions'])
                        ->mapWithKeys(fn (string $action) => [$action => "{$moduleKey}.{$featureKey}.{$action}"])
                        ->all(),
                ];
            }

            $modules[$moduleKey] = ['label' => $module['label'], 'features' => $features];
        }

        return $modules;
    }

    /**
     * Aksi yang benar-benar dipakai di katalog, urut sesuai kolom matriks.
     *
     * @return array<string, string> aksi => label
     */
    public static function actions(): array
    {
        $used = collect(self::modules())
            ->flatMap(fn (array $module) => $module['features'])
            ->flatMap(fn (array $feature) => array_keys($feature['permissions']))
            ->unique()
            ->all();

        return array_intersect_key(config('permission-matrix.actions', []), array_flip($used));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Pola dengan "*" (misal "inventory.*", "*.view") menjadi daftar nama izin di katalog.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $permission) => Str::is($patterns, $permission),
        ));
    }

    /** Label yang mudah dibaca, misal "Inventori · Produk · Ubah". */
    public static function label(string $permission): string
    {
        [$moduleKey, $featureKey, $action] = array_pad(explode('.', $permission, 3), 3, null);
        $module = config("permission-matrix.modules.{$moduleKey}");
        $feature = $module['features'][$featureKey] ?? null;

        if ($feature === null) {
            return $permission;
        }

        return implode(' · ', [$module['label'], $feature['label'], config("permission-matrix.actions.{$action}", $action)]);
    }
}
