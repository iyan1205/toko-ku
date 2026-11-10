<?php

namespace App\Providers;

use App\Models\GoodsReceipt;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Policies\RolePolicy;
use App\Services\Settings\PaymentMethodService;
use App\Support\CurrentBranch;
use App\Support\SettingStore;
use App\View\Composers\NavigationComposer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dibaca berkali-kali dalam satu request (helper setting(), label metode bayar,
        // cabang aktif di topbar): satu instance per request, dibuat ulang di request berikutnya.
        $this->app->scoped(SettingStore::class);
        $this->app->scoped(PaymentMethodService::class);
        $this->app->scoped(CurrentBranch::class);
    }

    public function boot(): void
    {
        // Super Admin melewati semua pengecekan izin.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // reference_type di stock_movements (dan payable_type di payments) memakai nama pendek.
        Relation::morphMap([
            'stock_opname' => StockOpname::class,
            'purchase' => Purchase::class,
            'sale' => Sale::class,
            'stock_transfer' => StockTransfer::class,
            'goods_receipt' => GoodsReceipt::class,
            'purchase_return' => PurchaseReturn::class,
            'sale_return' => SaleReturn::class,
            'payment' => Payment::class,
            'journal' => Journal::class,
        ]);

        // Menu sidebar, breadcrumb, notifikasi stok, dan nama toko untuk layout aplikasi & kasir.
        View::composer(['layouts.app', 'layouts.pos'], NavigationComposer::class);

        // @rupiah($nilai) dan @tanggal($tanggal) di Blade.
        Blade::directive('rupiah', fn (string $ekspresi) => '<?php echo \App\Support\Format::rupiah('.$ekspresi.'); ?>');
        Blade::directive('tanggal', fn (string $ekspresi) => '<?php echo \App\Support\Format::tanggal('.$ekspresi.'); ?>');
    }
}
