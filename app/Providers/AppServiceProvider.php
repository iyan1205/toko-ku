<?php

namespace App\Providers;

use App\Models\Purchase;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin melewati semua pengecekan izin.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // reference_type di stock_movements memakai nama pendek. Modul berikutnya menambah 'sale', 'purchase', dst.
        Relation::morphMap([
            'stock_opname' => StockOpname::class,
            'purchase' => Purchase::class,
        ]);

        // @rupiah($nilai) dan @tanggal($tanggal) di Blade.
        Blade::directive('rupiah', fn (string $ekspresi) => '<?php echo \App\Support\Format::rupiah(' . $ekspresi . '); ?>');
        Blade::directive('tanggal', fn (string $ekspresi) => '<?php echo \App\Support\Format::tanggal(' . $ekspresi . '); ?>');
    }
}
