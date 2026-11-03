<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProfileRequest;
use App\Services\StoreProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Profil toko: identitas yang muncul di kepala struk (nama, alamat, telepon,
 * Instagram, TikTok, nama pemilik) beserta kebutuhan printer (lebar kertas).
 *
 * Izin manage-settings dipasang di routes/web.php lewat middleware 'can:'.
 */
class SettingController extends Controller
{
    public function __construct(private readonly StoreProfileService $profil)
    {
    }

    public function edit(): View
    {
        return view('settings.store', [
            'profil' => $this->profil->ambil(),
            'lebarDidukung' => StoreProfileService::LEBAR_DIDUKUNG,
        ]);
    }

    public function update(StoreProfileRequest $request): RedirectResponse
    {
        $this->profil->simpan($request->validated());

        return redirect()->route('settings.edit')
            ->with('success', 'Profil toko tersimpan. Struk berikutnya memakai data ini.');
    }
}
