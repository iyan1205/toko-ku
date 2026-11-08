<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Layout layar penuh untuk Kasir: tanpa sidebar, dioptimalkan untuk tablet dan barcode scanner.
 */
class PosLayout extends Component
{
    public function render(): View
    {
        return view('layouts.pos');
    }
}
