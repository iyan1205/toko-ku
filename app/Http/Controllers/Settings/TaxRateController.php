<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengaturan → Tarif Pajak: tarif PPh potong dengan tanggal berlaku. Perubahan tarif dicatat sebagai
 * baris baru (tanggal berlaku baru), sehingga dokumen lama tetap memakai tarif saat itu.
 */
class TaxRateController extends Controller
{
    public function index(): View
    {
        return view('settings.tax-rates', [
            'groups' => TaxRate::query()->orderBy('code')->orderByDesc('effective_from')->get()->groupBy('code'),
            'kinds' => TaxRate::KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(TaxRate::KINDS))],
            'code' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Z.\-_]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            // whereDate: kolom tanggal di SQLite tersimpan dengan jam, jadi Rule::unique tidak cocok.
            'effective_from' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                if (strtotime((string) $value) !== false && TaxRate::query()->where('code', $request->input('code'))->whereDate('effective_from', date('Y-m-d', strtotime((string) $value)))->exists()) {
                    $fail('Kode ini sudah punya tarif dengan tanggal berlaku yang sama.');
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['kind' => 'jenis', 'code' => 'kode', 'name' => 'nama', 'rate' => 'tarif', 'effective_from' => 'berlaku mulai']);
        TaxRate::create($data);

        return redirect()->route('settings.tax-rates.index')->with('success', "Tarif {$data['name']} {$data['rate']}% berlaku mulai ".Format::tanggal($data['effective_from']).' disimpan.');
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return redirect()->route('settings.tax-rates.index')->with('success', "Tarif {$taxRate->name} ({$taxRate->effective_from->format('d-m-Y')}) dihapus. Dokumen yang sudah memakainya tidak berubah.");
    }
}
