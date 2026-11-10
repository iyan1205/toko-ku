<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PosSettingsRequest;
use App\Services\Pos\CashSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Pengaturan Kasir (setting pos.*). Izin settings.pos.* dipasang di routes/modules/settings.php. */
class PosSettingController extends Controller
{
    public function edit(CashSessionService $sessions): View
    {
        return view('settings.pos', ['requireSession' => $sessions->isRequired()]);
    }

    public function update(PosSettingsRequest $request, CashSessionService $sessions): RedirectResponse
    {
        $before = $sessions->isRequired();
        $after = (bool) $request->validated('require_session');

        setting(['pos.require_session' => $after]);

        if ($before !== $after) {
            activity('pengaturan')->causedBy($request->user())->event('updated')
                ->withProperties([
                    'old' => ['wajib_sesi_kasir' => $before ? 'ya' : 'tidak'],
                    'attributes' => ['wajib_sesi_kasir' => $after ? 'ya' : 'tidak'],
                ])
                ->log('Pengaturan kasir diubah');
        }

        return redirect()->route('settings.pos.edit')->with('success', 'Pengaturan kasir tersimpan.');
    }
}
