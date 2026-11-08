<?php

namespace App\Services\Sdm;

use App\Enums\PerangkatAbsensi;
use App\Enums\StatusAbsensi;
use App\Exceptions\AturanBisnisException;
use App\Models\Absensi;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Absen masuk/pulang oleh pegawai sendiri, dan catatan manual oleh admin.
 *
 * Satu pegawai hanya punya satu catatan per tanggal (dijaga unique index).
 * Absen pulang hanya untuk catatan hari ini: shift yang melewati tengah malam
 * atau lupa absen pulang dikoreksi admin lewat menu ubah absensi.
 */
class AbsensiService
{
    public function __construct(private readonly JadwalService $jadwal) {}

    public function hariIni(User $user): ?Absensi
    {
        return Absensi::query()
            ->where('user_id', $user->id)
            ->whereDate('tanggal', today())
            ->first();
    }

    public function masuk(User $user, ?string $lokasi, ?string $ip, ?string $userAgent): Absensi
    {
        $sekarang = now();

        if ($ada = $this->hariIni($user)) {
            throw new AturanBisnisException($this->pesanSudahAda($ada));
        }

        try {
            return Absensi::create([
                'user_id' => $user->id,
                'tanggal' => $sekarang->toDateString(),
                'status' => $this->statusMasuk($user, $sekarang),
                'clock_in' => $sekarang,
                'device_type' => PerangkatAbsensi::dariUserAgent($userAgent),
                'ip_address' => $ip,
                'location' => $lokasi,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan bersamaan (tombol ditekan dua kali): yang kedua ditolak oleh unique index.
            throw new AturanBisnisException('Anda sudah absen masuk hari ini.');
        }
    }

    public function pulang(User $user): Absensi
    {
        $absensi = $this->hariIni($user);

        if ($absensi === null || $absensi->clock_in === null) {
            throw new AturanBisnisException('Anda belum absen masuk hari ini.');
        }

        // Update bersyarat supaya dua permintaan bersamaan tidak saling menimpa jam pulang.
        $berubah = Absensi::query()
            ->whereKey($absensi->id)
            ->whereNull('clock_out')
            ->update(['clock_out' => now()]);

        if ($berubah === 0) {
            throw new AturanBisnisException(
                'Anda sudah absen pulang hari ini pukul '.$absensi->refresh()->clock_out->format('H:i').'.'
            );
        }

        return $absensi->refresh();
    }

    /**
     * Catatan manual admin: izin/sakit/alpa, atau koreksi jam masuk/pulang.
     * Untuk status tanpa jam (izin/sakit/alpa) jam masuk/pulang dikosongkan.
     *
     * @param  array{user_id:int|string,tanggal:string,status:string,jam_masuk?:?string,jam_pulang?:?string,keterangan?:?string}  $data
     */
    public function simpan(array $data, User $pencatat, ?Absensi $absensi = null): Absensi
    {
        $status = StatusAbsensi::from($data['status']);
        $tanggal = Carbon::parse($data['tanggal'])->startOfDay();

        $absensi ??= new Absensi;
        $absensi->fill([
            'user_id' => (int) $data['user_id'],
            'tanggal' => $tanggal->toDateString(),
            'status' => $status,
            'clock_in' => $status->pakaiJam() ? $this->gabung($tanggal, $data['jam_masuk'] ?? null) : null,
            'clock_out' => $status->pakaiJam() ? $this->gabung($tanggal, $data['jam_pulang'] ?? null) : null,
            'keterangan' => $data['keterangan'] ?? null,
            'recorded_by' => $pencatat->id,
        ]);

        try {
            $absensi->save();
        } catch (UniqueConstraintViolationException) {
            throw new AturanBisnisException('Pegawai ini sudah punya catatan absensi pada tanggal tersebut.');
        }

        return $absensi;
    }

    /**
     * Terlambat bila absen masuk lewat jam masuk + toleransi.
     * Jam masuk diambil dari shift di jadwal hari itu; hari libur tidak pernah terlambat;
     * tanpa jadwal memakai pos.absensi.jam_masuk. Tanpa jam masuk yang valid, selalu Hadir.
     */
    private function statusMasuk(User $user, CarbonInterface $waktu): StatusAbsensi
    {
        $jadwal = $this->jadwal->untukTanggal($user, $waktu);

        if ($jadwal?->libur()) {
            return StatusAbsensi::Hadir;
        }

        $jamMasuk = $jadwal?->shift?->jam_masuk ?? config('pos.absensi.jam_masuk');

        if (! is_string($jamMasuk) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamMasuk)) {
            return StatusAbsensi::Hadir;
        }

        $batas = $waktu->copy()
            ->setTimeFromTimeString($jamMasuk)
            ->addMinutes(max(0, (int) config('pos.absensi.toleransi_menit', 0)));

        return $waktu->greaterThan($batas) ? StatusAbsensi::Terlambat : StatusAbsensi::Hadir;
    }

    private function gabung(CarbonInterface $tanggal, ?string $jam): ?CarbonInterface
    {
        return $jam === null || $jam === '' ? null : $tanggal->copy()->setTimeFromTimeString($jam);
    }

    private function pesanSudahAda(Absensi $absensi): string
    {
        if ($absensi->clock_in !== null) {
            return 'Anda sudah absen masuk hari ini pukul '.$absensi->clock_in->format('H:i').'.';
        }

        return "Hari ini Anda sudah tercatat {$absensi->status->label()}. Hubungi admin bila perlu diubah.";
    }
}
