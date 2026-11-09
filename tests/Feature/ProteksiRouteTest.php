<?php

namespace Tests\Feature;

use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Penjaga konvensi hak akses: setiap route yang butuh login dijaga middleware permission,
 * dan setiap nama izin yang dipakai (route, menu, controller, view) ada di katalog.
 */
class ProteksiRouteTest extends TestCase
{
    /**
     * Route login tanpa middleware permission, dengan alasan:
     * dashboard (isi sesuai izin), pencarian & segera-hadir & ui-kit (izin dicek di controller),
     * profil (data milik sendiri), logout.
     */
    private const TANPA_MIDDLEWARE_IZIN = [
        'dashboard', 'search', 'coming-soon', 'dev.ui-kit',
        'profile.edit', 'profile.update', 'profile.password', 'logout',
    ];

    public function test_semua_route_yang_butuh_login_dijaga_middleware_permission(): void
    {
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true) || in_array($route->getName(), self::TANPA_MIDDLEWARE_IZIN, true)) {
                continue;
            }

            $this->assertTrue(
                collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')),
                "Route {$route->getName()} ({$route->uri()}) belum dijaga middleware permission:.",
            );
        }
    }

    public function test_semua_izin_yang_dipakai_route_menu_dan_kode_ada_di_katalog(): void
    {
        $dipakai = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'permission:')) {
                    array_push($dipakai, ...explode('|', substr($m, strlen('permission:'))));
                }
            }
        }

        foreach (config('menu') as $group) {
            foreach ($group['items'] as $item) {
                foreach ([$item, ...($item['children'] ?? [])] as $leaf) {
                    array_push($dipakai, ...(array) ($leaf['permission'] ?? []));
                }
            }
        }

        $modul = implode('|', array_keys(config('permission-matrix.modules')));
        foreach (File::allFiles([app_path(), resource_path('views')]) as $file) {
            preg_match_all(
                "/(?:can|cannot|canAny|authorize|allows|denies|@can|@cannot)\(\s*\[?([^)\]]*)/",
                $file->getContents(),
                $panggilan,
            );
            foreach ($panggilan[1] as $argumen) {
                preg_match_all("/['\"]((?:{$modul})\.[a-z-]+\.[a-z-]+)['\"]/", $argumen, $nama);
                array_push($dipakai, ...$nama[1]);
            }
        }

        $tidakDikenal = array_values(array_unique(array_filter($dipakai, fn ($izin) => ! PermissionCatalog::exists($izin))));

        $this->assertNotEmpty($dipakai);
        $this->assertSame([], $tidakDikenal, 'Izin dipakai tetapi tidak ada di config/permission-matrix.php.');
    }
}
