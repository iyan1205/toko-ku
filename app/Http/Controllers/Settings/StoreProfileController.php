<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreProfileRequest;
use App\Services\Settings\StoreProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Profil toko: identitas di kepala struk dan dokumen (nama, alamat, telepon, email, NPWP,
 * logo, media sosial). Lebar kertas dan footer struk ada di Struk & Printer.
 *
 * Izin settings.store.* dipasang di routes/modules/settings.php lewat middleware 'permission:'.
 */
class StoreProfileController extends Controller
{
    public function __construct(private readonly StoreProfileService $profil) {}

    public function edit(): View
    {
        return view('settings.store', ['profil' => $this->profil->ambil()]);
    }

    public function update(StoreProfileRequest $request): RedirectResponse
    {
        $this->profil->simpan($request->validated());

        return redirect()->route('settings.edit')
            ->with('success', 'Profil toko tersimpan. Struk berikutnya memakai data ini.');
    }
}
