<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SdmSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pengaturan SDM (setting sdm.*): hari kerja, jam & toleransi absen bawaan, selfie, dan rumus lembur.
 * Izin settings.sdm.* dipasang di routes/modules/settings.php.
 */
class SdmSettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.sdm', ['values' => self::values()]);
    }

    public function update(SdmSettingsRequest $request): RedirectResponse
    {
        $before = self::values();
        $data = $request->validated();

        setting(collect($data)->mapWithKeys(fn ($value, $field) => [SdmSettingsRequest::KEYS[$field] => $value])->all());

        $after = self::values();
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed !== []) {
            activity('pengaturan')->causedBy($request->user())->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log('Pengaturan SDM diubah');
        }

        return redirect()->route('settings.sdm.edit')->with('success', 'Pengaturan SDM tersimpan.');
    }

    /**
     * Nilai yang berlaku sekarang (dengan bawaannya), kunci = nama isian form.
     *
     * @return array<string, string|int|bool|null>
     */
    public static function values(): array
    {
        return [
            'workweek' => (int) setting('sdm.workweek', 6),
            'default_in' => setting('sdm.attendance.default_in', config('pos.absensi.jam_masuk')),
            'default_out' => setting('sdm.attendance.default_out'),
            'late_tolerance' => (int) setting('sdm.attendance.late_tolerance', config('pos.absensi.toleransi_menit', 0)),
            'early_tolerance' => (int) setting('sdm.attendance.early_tolerance', 0),
            'require_selfie' => setting('sdm.attendance.require_selfie', '0') === '1',
        ];
    }
}
