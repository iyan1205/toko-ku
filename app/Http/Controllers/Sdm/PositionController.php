<?php

namespace App\Http\Controllers\Sdm;

use App\Exports\Sdm\PositionsExport;
use App\Http\Requests\Sdm\PositionRequest;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;

class PositionController extends OrganizationUnitController
{
    public function store(PositionRequest $request): RedirectResponse
    {
        return $this->storeUnit($request, Position::class);
    }

    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        return $this->updateUnit($request, $position);
    }

    public function destroy(Position $position): RedirectResponse
    {
        return $this->destroyUnit($position);
    }

    protected function exportClass(): string
    {
        return PositionsExport::class;
    }

    protected function label(): string
    {
        return 'jabatan';
    }

    protected function prefix(): string
    {
        return 'positions';
    }
}
