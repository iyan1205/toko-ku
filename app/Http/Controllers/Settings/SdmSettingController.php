<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OvertimeRuleRequest;
use App\Http\Requests\Settings\SdmSettingsRequest;
use App\Models\OvertimeRule;
use App\Support\Format;
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
        return view('settings.sdm', [
            'values' => self::values(),
            'overtimeRules' => OvertimeRule::query()->orderByDesc('effective_from')->get(),
        ]);
    }

    /** Aturan upah lembur baru (berlaku mulai tanggal itu; aturan lama tetap untuk lembur sebelumnya). */
    public function storeOvertimeRule(OvertimeRuleRequest $request): RedirectResponse
    {
        $rule = OvertimeRule::create($request->ruleData());

        return redirect()->route('settings.sdm.edit')->with('success', 'Aturan upah lembur mulai '.Format::tanggal($rule->effective_from).' tersimpan.');
    }

    public function destroyOvertimeRule(OvertimeRule $rule): RedirectResponse
    {
        if (OvertimeRule::count() === 1) {
            throw new AturanBisnisException('Aturan upah lembur terakhir tidak bisa dihapus. Tambahkan aturan baru bila aturannya berubah.');
        }
        $rule->delete();

        return redirect()->route('settings.sdm.edit')->with('success', 'Aturan upah lembur mulai '.Format::tanggal($rule->effective_from).' dihapus.');
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
            'correction_days' => (int) setting('sdm.attendance.correction_days', 30),
            'period_start_day' => (int) setting('sdm.attendance.period_start_day', 1),
        ];
    }
}
