<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/** Log aktivitas: siapa melakukan apa dan kapan. Hanya-baca. */
class ActivityLogController extends Controller
{
    /** Kelompok log yang dipakai aplikasi => label. */
    public const LOG_NAMES = [
        'auth' => 'Login & logout',
        'pengguna' => 'Pengguna',
        'hak-akses' => 'Peran & hak akses',
        'inventori' => 'Inventori',
        'penjualan' => 'Penjualan',
        'pembelian' => 'Pembelian',
        'sdm' => 'SDM',
        'akuntansi' => 'Akuntansi',
        'persetujuan' => 'Persetujuan',
        'dokumen' => 'Lampiran dokumen',
        'impor' => 'Impor data',
        'pengaturan' => 'Pengaturan',
    ];

    public function __invoke(Request $request): View
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kelompok' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::LOG_NAMES))],
            'pengguna' => ['nullable', 'integer'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->when($filter['cari'] ?? null, fn ($q, $kata) => $q->where('description', 'like', '%'.$kata.'%'))
            ->when($filter['kelompok'] ?? null, fn ($q, $log) => $q->where('log_name', $log))
            ->when($filter['pengguna'] ?? null, fn ($q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))
            ->when($filter['dari'] ?? null, fn ($q, $tanggal) => $q->whereDate('created_at', '>=', $tanggal))
            ->when($filter['sampai'] ?? null, fn ($q, $tanggal) => $q->whereDate('created_at', '<=', $tanggal))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('access.activity-log.index', [
            'activities' => $activities,
            'logNames' => self::LOG_NAMES,
            'users' => User::withTrashed()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
