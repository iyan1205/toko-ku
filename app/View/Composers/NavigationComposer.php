<?php

namespace App\View\Composers;

use App\Models\Product;
use App\Models\User;
use App\Services\Settings\StoreProfileService;
use App\Support\CurrentBranch;
use App\Support\Menu;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Data navigasi untuk layouts.app dan layouts.pos, dihitung sekali per halaman:
 * menu sidebar, menu aktif (breadcrumb), jumlah notifikasi stok, nama toko, dan cabang aktif.
 */
class NavigationComposer
{
    public function __construct(
        private readonly StoreProfileService $storeProfile,
        private readonly CurrentBranch $currentBranch,
    ) {}

    public function compose(View $view): void
    {
        $user = Auth::user();

        $view->with('storeName', $this->storeProfile->ambil()['nama_toko']);

        if ($view->name() !== 'layouts.app') {
            return;
        }

        $tree = Menu::tree($user);

        $view->with([
            'menuTree' => $tree,
            'menuCurrent' => Menu::current($tree),
            'restockCount' => $this->restockCount($user),
            'branchOptions' => $this->currentBranch->options(),
            'currentBranch' => $this->currentBranch->get(),
            'canChooseAllBranches' => $this->currentBranch->canChooseAll(),
        ]);
    }

    /**
     * Jumlah produk yang perlu diisi ulang, untuk angka di ikon notifikasi. Daftarnya baru
     * diambil saat dropdown dibuka (NotificationController). Null bila tidak boleh melihat stok.
     */
    private function restockCount(?User $user): ?int
    {
        if (! Menu::allows('inventory.stock.view', $user)) {
            return null;
        }

        return Product::query()->needsRestock()->count();
    }
}
