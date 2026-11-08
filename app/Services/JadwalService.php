<?php

namespace App\Services;

use App\Models\Jadwal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal kerja mingguan: satu sel = shift satu pegawai pada satu tanggal.
 * Tanggal dicari dengan whereDate supaya sama di MySQL dan SQLite.
 */
class JadwalService
{
    /** Senin dari minggu yang memuat tanggal ini (bawaan: minggu berjalan). */
    public function awalMinggu(?string $tanggal = null): CarbonImmutable
    {
        return CarbonImmutable::parse($tanggal ?? today())->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** @return Collection<int, CarbonImmutable> Senin sampai Minggu. */
    public function hariDalamMinggu(CarbonImmutable $senin): Collection
    {
        return collect(range(0, 6))->map(fn (int $i) => $senin->addDays($i));
    }

    public function untukTanggal(User $user, CarbonInterface $tanggal): ?Jadwal
    {
        return Jadwal::query()
            ->with('shift')
            ->where('user_id', $user->id)
            ->whereDate('tanggal', $tanggal->toDateString())
            ->first();
    }

    /**
     * Jadwal beberapa pegawai dalam satu minggu, dengan kunci "user_id|Y-m-d".
     *
     * @param  array<int, int>  $userIds
     * @return Collection<string, Jadwal>
     */
    public function dalamMinggu(CarbonImmutable $senin, array $userIds): Collection
    {
        return Jadwal::query()
            ->with('shift')
            ->whereIn('user_id', $userIds)
            ->whereDate('tanggal', '>=', $senin->toDateString())
            ->whereDate('tanggal', '<=', $senin->addDays(6)->toDateString())
            ->get()
            ->keyBy(fn (Jadwal $j) => $j->user_id.'|'.$j->tanggal->toDateString());
    }

    /**
     * Simpan isi grid satu minggu. Nilai sel: id shift, "libur", atau kosong (jadwal dihapus).
     *
     * @param  array<int|string, array<string, ?string>>  $isian  [user_id => [Y-m-d => nilai]]
     */
    public function simpanMinggu(CarbonImmutable $senin, array $isian): void
    {
        DB::transaction(function () use ($senin, $isian) {
            $ada = $this->dalamMinggu($senin, array_map('intval', array_keys($isian)));

            foreach ($isian as $userId => $hari) {
                foreach ($hari as $tanggal => $nilai) {
                    $jadwal = $ada->get($userId.'|'.$tanggal);

                    if ($nilai === null || $nilai === '') {
                        $jadwal?->delete();

                        continue;
                    }

                    $jadwal ??= new Jadwal(['user_id' => (int) $userId, 'tanggal' => $tanggal]);
                    $jadwal->shift_id = $nilai === 'libur' ? null : (int) $nilai;
                    $jadwal->save();
                }
            }
        });
    }

    /**
     * Salin jadwal minggu sebelumnya ke minggu ini, hanya ke sel yang masih kosong.
     *
     * @param  array<int, int>  $userIds
     * @return int jumlah sel yang terisi
     */
    public function salinDariMingguLalu(CarbonImmutable $senin, array $userIds): int
    {
        return DB::transaction(function () use ($senin, $userIds) {
            $sudahAda = $this->dalamMinggu($senin, $userIds);
            $jumlah = 0;

            foreach ($this->dalamMinggu($senin->subWeek(), $userIds) as $sumber) {
                $tanggal = $sumber->tanggal->copy()->addWeek()->toDateString();

                if ($sudahAda->has($sumber->user_id.'|'.$tanggal)) {
                    continue;
                }

                Jadwal::create(['user_id' => $sumber->user_id, 'tanggal' => $tanggal, 'shift_id' => $sumber->shift_id]);
                $jumlah++;
            }

            return $jumlah;
        });
    }
}
