<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\InventorySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Pengaturan Inventori (setting inventory.*): metode HPP, batch kedaluwarsa. Izin settings.inventory.*. */
class InventorySettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.inventory', ['values' => self::values()]);
    }

    public function update(InventorySettingsRequest $request): RedirectResponse
    {
        $before = self::values();
        setting(collect($request->validated())->mapWithKeys(fn ($v, $k) => [InventorySettingsRequest::KEYS[$k] => $v])->all());
        $after = self::values();
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        if ($changed !== []) {
            activity('pengaturan')->causedBy($request->user())->event('updated')
                ->withProperties(['old' => array_intersect_key($before, array_flip($changed)), 'attributes' => array_intersect_key($after, array_flip($changed))])
                ->log('Pengaturan inventori diubah');
        }

        return redirect()->route('settings.inventory.edit')->with('success', 'Pengaturan inventori tersimpan.');
    }

    /** @return array{costing_method: string, block_expired_sales: bool, expiry_warning_days: int, slow_moving_days: int} */
    public static function values(): array
    {
        return [
            'costing_method' => setting('inventory.costing_method', 'average') === 'fifo' ? 'fifo' : 'average',
            'block_expired_sales' => setting('inventory.block_expired_sales', '1') === '1',
            'expiry_warning_days' => (int) setting('inventory.expiry_warning_days', 30),
            'slow_moving_days' => (int) setting('inventory.slow_moving_days', 60),
        ];
    }
}
