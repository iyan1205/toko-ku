<?php

namespace App\Services;

use App\Enums\StatusCuti;
use App\Enums\StatusPersetujuan;
use App\Exceptions\AturanBisnisException;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\PersetujuanCuti;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan cuti dengan persetujuan bertingkat.
 *
 * - Tahap persetujuan disalin dari jenis cuti saat diajukan, lalu diputuskan berurutan (tahap 1, 2, ...).
 * - Tiap tahap diputuskan oleh orang yang ditunjuk, atau siapa pun dengan peran tahap itu (kecuali pemohon sendiri).
 *   Super Admin boleh memutuskan tahap mana pun supaya pengajuan tidak tertahan bila approver berhalangan.
 * - Satu penolakan mengakhiri pengajuan. Setelah tahap terakhir disetujui, tanggal cuti dicatat ke absensi.
 * - Pengajuan yang masih menunggu ikut memakai kuota supaya tidak bisa mengajukan melebihi kuota sekaligus.
 */
class CutiService
{
    public function __construct(private readonly KodeService $kode) {}

    /** Hari kalender, termasuk tanggal mulai dan selesai. */
    public function hitungHari(CarbonInterface $mulai, CarbonInterface $selesai): int
    {
        return (int) $mulai->copy()->startOfDay()->diffInDays($selesai->copy()->startOfDay()) + 1;
    }

    /**
     * Hari cuti yang sudah dipakai (menunggu + disetujui) per jenis cuti pada satu tahun.
     *
     * @return Collection<int, int> [jenis_cuti_id => jumlah hari]
     */
    public function terpakaiPerJenis(User $user, int $tahun): Collection
    {
        return Cuti::query()
            ->where('user_id', $user->id)
            ->whereIn('status', StatusCuti::valuesBerlaku())
            ->whereYear('tanggal_mulai', $tahun)
            ->groupBy('jenis_cuti_id')
            ->selectRaw('jenis_cuti_id, sum(jumlah_hari) as jumlah')
            ->pluck('jumlah', 'jenis_cuti_id')
            ->map(fn ($jumlah) => (int) $jumlah);
    }

    /** Sisa kuota, atau null bila jenis cuti ini tanpa kuota. */
    public function sisaKuota(User $user, JenisCuti $jenis, int $tahun): ?int
    {
        if (! $jenis->punyaKuota()) {
            return null;
        }

        return max(0, $jenis->kuota_tahunan - (int) $this->terpakaiPerJenis($user, $tahun)->get($jenis->id, 0));
    }

    /** @param  array{jenis_cuti_id:int|string,tanggal_mulai:string,tanggal_selesai:string,alasan:string}  $data */
    public function ajukan(User $pemohon, array $data): Cuti
    {
        $jenis = JenisCuti::query()->with('tahap')->findOrFail($data['jenis_cuti_id']);
        $mulai = Carbon::parse($data['tanggal_mulai'])->startOfDay();
        $selesai = Carbon::parse($data['tanggal_selesai'])->startOfDay();

        if ($mulai->year !== $selesai->year) {
            throw new AturanBisnisException('Cuti tidak boleh melewati pergantian tahun. Ajukan terpisah untuk tiap tahun.');
        }

        $hari = $this->hitungHari($mulai, $selesai);

        return DB::transaction(function () use ($pemohon, $jenis, $mulai, $selesai, $hari, $data) {
            // Kunci baris pegawai: dua pengajuan bersamaan tidak boleh sama-sama lolos cek kuota dan tanggal bentrok.
            User::query()->whereKey($pemohon->id)->lockForUpdate()->first();

            if ($bentrok = $this->bentrok($pemohon, $mulai, $selesai)) {
                throw new AturanBisnisException("Tanggal ini bertabrakan dengan pengajuan {$bentrok->nomor} ({$bentrok->rentangTanggal()}).");
            }

            $sisa = $this->sisaKuota($pemohon, $jenis, $mulai->year);
            if ($sisa !== null && $hari > $sisa) {
                throw new AturanBisnisException("Sisa kuota {$jenis->nama} tahun {$mulai->year} tinggal {$sisa} hari, sedangkan pengajuan ini {$hari} hari.");
            }

            $tanpaPersetujuan = $jenis->tahap->isEmpty();

            $cuti = Cuti::create([
                'nomor' => $this->kode->berikutnya(Cuti::class, 'nomor', 'CT-', 5),
                'user_id' => $pemohon->id,
                'jenis_cuti_id' => $jenis->id,
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari' => $hari,
                'alasan' => $data['alasan'],
                'status' => $tanpaPersetujuan ? StatusCuti::Disetujui : StatusCuti::Menunggu,
                'tahap_aktif' => $jenis->tahap->first()?->urutan,
            ]);

            foreach ($jenis->tahap as $tahap) {
                $cuti->persetujuan()->create([
                    'urutan' => $tahap->urutan,
                    'peran' => $tahap->peran,
                    'approver_id' => $tahap->approver_id,
                ]);
            }

            if ($tanpaPersetujuan) {
                $this->catatKeAbsensi($cuti, $pemohon);
            }

            return $cuti->load('persetujuan');
        });
    }

    /** Boleh memutuskan tahap yang sedang aktif? Pemohon tidak boleh menyetujui pengajuannya sendiri. */
    public function bisaMemutuskan(User $user, Cuti $cuti): bool
    {
        $tahap = $cuti->tahapSekarang();

        if ($tahap === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $cuti->user_id !== $user->id && $tahap->ditujukanKe($user);
    }

    public function setujui(Cuti $cuti, User $penyetuju, ?string $catatan = null): Cuti
    {
        return DB::transaction(function () use ($cuti, $penyetuju, $catatan) {
            $cuti = $this->kunci($cuti);
            $tahap = $this->tahapUntuk($cuti, $penyetuju);

            $this->putuskan($tahap, StatusPersetujuan::Disetujui, $penyetuju, $catatan);

            $berikutnya = $cuti->persetujuan->first(fn (PersetujuanCuti $p) => $p->urutan > $tahap->urutan);

            if ($berikutnya !== null) {
                $cuti->update(['tahap_aktif' => $berikutnya->urutan]);
            } else {
                $cuti->update(['status' => StatusCuti::Disetujui, 'tahap_aktif' => null]);
                $this->catatKeAbsensi($cuti, $penyetuju);
            }

            return $cuti->refresh()->load('persetujuan');
        });
    }

    public function tolak(Cuti $cuti, User $penyetuju, string $catatan): Cuti
    {
        return DB::transaction(function () use ($cuti, $penyetuju, $catatan) {
            $cuti = $this->kunci($cuti);
            $tahap = $this->tahapUntuk($cuti, $penyetuju);

            $this->putuskan($tahap, StatusPersetujuan::Ditolak, $penyetuju, $catatan);
            $cuti->update(['status' => StatusCuti::Ditolak, 'tahap_aktif' => null]);

            return $cuti->refresh()->load('persetujuan');
        });
    }

    /** Pemohon menarik pengajuan yang belum selesai diputuskan. Kuotanya kembali. */
    public function batalkan(Cuti $cuti): Cuti
    {
        return DB::transaction(function () use ($cuti) {
            $cuti = $this->kunci($cuti);

            if ($cuti->status !== StatusCuti::Menunggu) {
                throw new AturanBisnisException("Pengajuan {$cuti->nomor} sudah {$this->labelKecil($cuti)} dan tidak dapat dibatalkan.");
            }

            $cuti->update(['status' => StatusCuti::Dibatalkan, 'tahap_aktif' => null]);

            return $cuti;
        });
    }

    private function bentrok(User $user, CarbonInterface $mulai, CarbonInterface $selesai): ?Cuti
    {
        return Cuti::query()
            ->where('user_id', $user->id)
            ->whereIn('status', StatusCuti::valuesBerlaku())
            ->whereDate('tanggal_mulai', '<=', $selesai->toDateString())
            ->whereDate('tanggal_selesai', '>=', $mulai->toDateString())
            ->first();
    }

    /** Baca ulang pengajuan dengan baris terkunci, supaya dua approver tidak memutuskan tahap yang sama bersamaan. */
    private function kunci(Cuti $cuti): Cuti
    {
        return Cuti::query()->with(['persetujuan', 'jenisCuti'])->lockForUpdate()->findOrFail($cuti->id);
    }

    private function tahapUntuk(Cuti $cuti, User $penyetuju): PersetujuanCuti
    {
        if ($cuti->status !== StatusCuti::Menunggu) {
            throw new AturanBisnisException("Pengajuan {$cuti->nomor} sudah {$this->labelKecil($cuti)}.");
        }

        if (! $this->bisaMemutuskan($penyetuju, $cuti)) {
            throw new AturanBisnisException("Tahap {$cuti->tahap_aktif} pengajuan {$cuti->nomor} bukan untuk Anda putuskan.");
        }

        return $cuti->tahapSekarang();
    }

    private function putuskan(PersetujuanCuti $tahap, StatusPersetujuan $status, User $oleh, ?string $catatan): void
    {
        $tahap->update([
            'status' => $status,
            'diputuskan_oleh' => $oleh->id,
            'diputuskan_pada' => now(),
            'catatan' => $catatan,
        ]);
    }

    /**
     * Catat tiap tanggal cuti ke absensi dengan status dari jenis cuti.
     * Tanggal yang sudah punya catatan absensi (mis. pegawai tetap masuk) tidak diubah.
     */
    private function catatKeAbsensi(Cuti $cuti, User $pencatat): void
    {
        $cuti->loadMissing('jenisCuti');

        $sudahAda = Absensi::query()
            ->where('user_id', $cuti->user_id)
            ->whereDate('tanggal', '>=', $cuti->tanggal_mulai->toDateString())
            ->whereDate('tanggal', '<=', $cuti->tanggal_selesai->toDateString())
            ->pluck('tanggal')
            ->map(fn ($tanggal) => Carbon::parse($tanggal)->toDateString())
            ->flip();

        foreach (CarbonPeriod::create($cuti->tanggal_mulai, $cuti->tanggal_selesai) as $tanggal) {
            if ($sudahAda->has($tanggal->toDateString())) {
                continue;
            }

            try {
                Absensi::create([
                    'user_id' => $cuti->user_id,
                    'tanggal' => $tanggal->toDateString(),
                    'status' => $cuti->jenisCuti->status_absensi,
                    'keterangan' => "{$cuti->jenisCuti->nama} · {$cuti->nomor}",
                    'recorded_by' => $pencatat->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Pegawai absen masuk pada detik yang sama: catatan kehadirannya yang dipertahankan.
            }
        }
    }

    private function labelKecil(Cuti $cuti): string
    {
        return mb_strtolower($cuti->status->label());
    }
}
