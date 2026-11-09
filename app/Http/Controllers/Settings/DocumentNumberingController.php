<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DocumentType;
use App\Enums\NumberingReset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DocumentNumberingRequest;
use App\Services\Settings\DocumentNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Format nomor dokumen. Izin settings.numbering.* dipasang di routes/modules/settings.php. */
class DocumentNumberingController extends Controller
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function edit(): View
    {
        return view('settings.numbering', [
            'documents' => collect(DocumentType::cases())->map(fn (DocumentType $type) => [
                'type' => $type,
                'format' => $this->numbers->format($type),
                'example' => $this->numbers->example($type),
            ]),
            'resets' => NumberingReset::cases(),
        ]);
    }

    public function update(DocumentNumberingRequest $request): RedirectResponse
    {
        $this->numbers->save($request->validated('formats'), $request->user());

        return redirect()->route('settings.numbering.edit')
            ->with('success', 'Format nomor tersimpan. Berlaku untuk dokumen baru; nomor dokumen lama tidak berubah.');
    }
}
