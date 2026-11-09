<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TaxSettingsRequest;
use App\Services\Settings\TaxSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Pengaturan pajak penjualan. Izin settings.taxes.* dipasang di routes/modules/settings.php. */
class TaxController extends Controller
{
    public function __construct(private readonly TaxSettingsService $taxes) {}

    public function edit(): View
    {
        return view('settings.taxes', ['tax' => $this->taxes->get()]);
    }

    public function update(TaxSettingsRequest $request): RedirectResponse
    {
        $this->taxes->save($request->validated(), $request->user());

        return redirect()->route('settings.taxes.edit')->with('success', 'Pengaturan pajak tersimpan.');
    }
}
