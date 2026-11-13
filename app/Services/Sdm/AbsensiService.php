<?php

namespace App\Services\Sdm;

use App\Enums\PerangkatAbsensi;
use App\Enums\StatusAbsensi;
use App\Exceptions\AturanBisnisException;
use App\Models\Absensi;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
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
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly WorkCalendar $calendar,
    ) {}

    public function hariIni(User $user): ?Absensi
    {
        $employee = $user->employee;

        return Absensi::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->when($employee, fn ($w) => $w->orWhere('employee_id', $employee->id)))
            ->whereDate('tanggal', today())
            ->first();
    }

    /**
     * Absen masuk sendiri (halaman Absensi / Layanan Mandiri). Bila cabang karyawan punya titik
     * dan radius absen (Pengaturan → Cabang), lokasi wajib dan harus di dalam radius. Foto selfie
     * wajib bila Pengaturan → SDM memintanya.
     */
    public function masuk(User $user, ?string $lokasi, ?string $ip, ?string $userAgent, ?UploadedFile $selfie = null): Absensi
    {
        $sekarang = now();

        if ($ada = $this->hariIni($user)) {
            throw new AturanBisnisException($this->pesanSudahAda($ada));
        }

        $employee = $user->employee;
        $jarak = $this->periksaLokasi($employee, $lokasi);
        $this->periksaSelfie($selfie);
        [$status, $terlambat] = $this->statusMasuk($user, $employee, $sekarang);

        try {
            return Absensi::create([
                'user_id' => $user->id,
                'employee_id' => $employee?->id,
                'tanggal' => $sekarang->toDateString(),
                'status' => $status,
                'clock_in' => $sekarang,
                'late_minutes' => $terlambat,
                'source' => 'web',
                'device_type' => PerangkatAbsensi::dariUserAgent($userAgent),
                'ip_address' => $ip,
                'location' => $lokasi,
                'distance_m' => $jarak,
                'clock_in_photo' => $selfie?->store('absensi/'.$sekarang->format('Y-m'), 'local'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan bersamaan (tombol ditekan dua kali): yang kedua ditolak oleh unique index.
            throw new AturanBisnisException('Anda sudah absen masuk hari ini.');
        }
    }

    public function pulang(User $user, ?string $lokasi = null, ?UploadedFile $selfie = null): Absensi
    {
        $absensi = $this->hariIni($user);

        if ($absensi === null || $absensi->clock_in === null) {
            throw new AturanBisnisException('Anda belum absen masuk hari ini.');
        }

        $this->periksaSelfie($selfie);
        $sekarang = now();
        $pulangCepat = $this->menitPulangCepat($absensi, $user->employee, $sekarang);

        // Update bersyarat supaya dua permintaan bersamaan tidak saling menimpa jam pulang.
        $berubah = Absensi::query()
            ->whereKey($absensi->id)
            ->whereNull('clock_out')
            ->update([
                'clock_out' => $sekarang,
                'early_leave_minutes' => $pulangCepat,
                'clock_out_location' => $lokasi,
                'clock_out_photo' => $selfie?->store('absensi/'.$sekarang->format('Y-m'), 'local'),
            ]);

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
     * @param  array{user_id?:int|string|null,employee_id?:int|string|null,tanggal:string,status:string,jam_masuk?:?string,jam_pulang?:?string,keterangan?:?string}  $data
     */
    public function simpan(array $data, User $pencatat, ?Absensi $absensi = null, string $source = 'manual'): Absensi
    {
        $status = StatusAbsensi::from($data['status']);
        $tanggal = Carbon::parse($data['tanggal'])->startOfDay();

        $absensi ??= new Absensi;
        $absensi->fill([
            'user_id' => ! empty($data['user_id']) ? (int) $data['user_id'] : $absensi->user_id,
            'employee_id' => ! empty($data['employee_id']) ? (int) $data['employee_id'] : $absensi->employee_id,
            'tanggal' => $tanggal->toDateString(),
            'status' => $status,
            'clock_in' => $status->pakaiJam() ? $this->gabung($tanggal, $data['jam_masuk'] ?? null) : null,
            'clock_out' => $status->pakaiJam() ? $this->gabungPulang($tanggal, $data['jam_masuk'] ?? null, $data['jam_pulang'] ?? null) : null,
            'keterangan' => $data['keterangan'] ?? null,
            'recorded_by' => $pencatat->id,
            'source' => $source,
        ]);
        $this->hitungUlang($absensi);

        try {
            $absensi->save();
        } catch (UniqueConstraintViolationException) {
            throw new AturanBisnisException('Pegawai ini sudah punya catatan absensi pada tanggal tersebut.');
        }

        return $absensi;
    }

    /**
     * Satu scan dari mesin fingerprint: scan pertama hari itu = masuk, terakhir = pulang.
     * Scan dini hari setelah shift malam kemarin dihitung ke tanggal kemarin. Catatan izin/sakit/
     * cuti dari SDM tidak ditimpa. Mengembalikan 'created' atau 'updated'.
     */
    public function catatScan(Employee $employee, CarbonInterface $waktu, ?User $pencatat = null): string
    {
        $waktu = CarbonImmutable::parse($waktu);
        $tanggal = $this->tanggalScan($employee, $waktu);
        $absensi = Absensi::query()->where('employee_id', $employee->id)->whereDate('tanggal', $tanggal)->first();
        $baru = $absensi === null;

        if ($absensi !== null && ! $absensi->status->pakaiJam()) {
            return 'updated';
        }

        $absensi ??= new Absensi(['employee_id' => $employee->id, 'tanggal' => $tanggal, 'status' => StatusAbsensi::Hadir, 'source' => 'fingerprint']);
        $masuk = $absensi->clock_in ? CarbonImmutable::parse($absensi->clock_in) : null;
        $pulang = $absensi->clock_out ? CarbonImmutable::parse($absensi->clock_out) : null;
        $titik = collect([$masuk, $pulang, $waktu])->filter()->unique(fn ($t) => $t->format('Y-m-d H:i'))->sort()->values();

        $absensi->clock_in = $titik->first();
        $absensi->clock_out = $titik->count() > 1 ? $titik->last() : null;
        $absensi->recorded_by ??= $pencatat?->id;
        $this->hitungUlang($absensi);
        $absensi->save();

        return $baru ? 'created' : 'updated';
    }

    /** Hitung ulang status Hadir/Terlambat dan menit terlambat/pulang cepat dari jadwal hari itu. */
    public function hitungUlang(Absensi $absensi): void
    {
        if (! $absensi->status->pakaiJam() || $absensi->clock_in === null) {
            $absensi->late_minutes = 0;
            $absensi->early_leave_minutes = 0;

            return;
        }

        $employee = $absensi->employee_id ? Employee::withTrashed()->find($absensi->employee_id) : null;
        $user = $absensi->user_id ? User::withTrashed()->find($absensi->user_id) : null;
        [$status, $terlambat] = $this->statusMasuk($user, $employee, Carbon::parse($absensi->clock_in));
        $absensi->status = $status;
        $absensi->late_minutes = $terlambat;
        $absensi->early_leave_minutes = $absensi->clock_out ? $this->menitPulangCepat($absensi, $employee, Carbon::parse($absensi->clock_out)) : 0;
    }

    /**
     * Rentang kerja terjadwal untuk tanggal absensi: [mulai, selesai, toleransi telat, toleransi pulang]
     * atau null bila hari libur / tanpa jam kerja.
     *
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int, 3: int}|null
     */
    public function jamKerja(?User $user, ?Employee $employee, CarbonInterface $tanggal): ?array
    {
        $tanggal = CarbonImmutable::parse($tanggal)->startOfDay();

        if ($employee !== null) {
            $hari = $this->calendar->day($employee, $tanggal);
            if ($hari['type'] !== 'work') {
                return null;
            }
            $shift = $hari['shift'];
        } else {
            $jadwal = $user ? $this->jadwal->untukTanggal($user, $tanggal) : null;
            if ($jadwal?->libur()) {
                return null;
            }
            $shift = $jadwal?->shift;
        }

        return $this->rentangShift($shift, $tanggal);
    }

    /** @return array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: int, 3: int}|null */
    private function rentangShift(?Shift $shift, CarbonImmutable $tanggal): ?array
    {
        [$defaultIn, $defaultOut] = $this->calendar->defaultHours();
        $masuk = $shift?->jam_masuk ?? $defaultIn;
        $pulang = $shift?->jam_pulang ?? ($shift ? null : $defaultOut);

        if (! is_string($masuk) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $masuk)) {
            return null;
        }

        $mulai = $tanggal->setTimeFromTimeString($masuk);
        $selesai = is_string($pulang) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $pulang) ? $tanggal->setTimeFromTimeString($pulang) : null;
        if ($selesai !== null && $selesai->lte($mulai)) {
            $selesai = $selesai->addDay();
        }
        $telat = $shift?->lateTolerance() ?? (int) setting('sdm.attendance.late_tolerance', config('pos.absensi.toleransi_menit', 0));
        $cepat = $shift?->earlyLeaveTolerance() ?? (int) setting('sdm.attendance.early_tolerance', 0);

        return [$mulai, $selesai, max(0, $telat), max(0, $cepat)];
    }

    /**
     * Terlambat bila absen masuk lewat jam masuk + toleransi; menit terlambat dihitung dari jam masuk.
     * Hari libur dan hari tanpa jam kerja tidak pernah terlambat.
     *
     * @return array{0: StatusAbsensi, 1: int}
     */
    private function statusMasuk(?User $user, ?Employee $employee, CarbonInterface $waktu): array
    {
        $jam = $this->jamKerja($user, $employee, $waktu);
        if ($jam === null) {
            return [StatusAbsensi::Hadir, 0];
        }

        [$mulai, , $toleransi] = $jam;
        $waktu = CarbonImmutable::parse($waktu);

        return $waktu->greaterThan($mulai->addMinutes($toleransi))
            ? [StatusAbsensi::Terlambat, (int) $mulai->diffInMinutes($waktu)]
            : [StatusAbsensi::Hadir, 0];
    }

    private function menitPulangCepat(Absensi $absensi, ?Employee $employee, CarbonInterface $pulang): int
    {
        $user = $absensi->user_id ? User::withTrashed()->find($absensi->user_id) : null;
        $jam = $this->jamKerja($user, $employee, $absensi->tanggal);
        if ($jam === null || $jam[1] === null) {
            return 0;
        }

        [, $selesai, , $toleransi] = $jam;
        $pulang = CarbonImmutable::parse($pulang);

        return $pulang->lessThan($selesai->subMinutes($toleransi)) ? (int) $pulang->diffInMinutes($selesai) : 0;
    }

    /**
     * Syarat absen sendiri untuk form di layar: cabang dengan radius (lokasi wajib) dan selfie wajib.
     *
     * @return array{cabang: ?Branch, lokasi: bool, foto: bool}
     */
    public function aturanAbsen(User $user): array
    {
        $cabang = $this->cabangBerradius($user->employee);

        return [
            'cabang' => $cabang,
            'lokasi' => $cabang !== null,
            'foto' => setting('sdm.attendance.require_selfie', '0') === '1',
        ];
    }

    /** Cabang karyawan (atau cabang utama) bila cabang itu memakai titik + radius absen. */
    private function cabangBerradius(?Employee $employee): ?Branch
    {
        $cabang = $employee?->branch ?? Branch::query()->where('is_default', true)->first();

        return ($cabang?->latitude !== null && $cabang->longitude !== null && $cabang->attendance_radius_m) ? $cabang : null;
    }

    /** Jarak dari titik cabang (meter), atau null bila cabang tanpa titik. Ditolak di luar radius. */
    private function periksaLokasi(?Employee $employee, ?string $lokasi): ?int
    {
        $cabang = $this->cabangBerradius($employee);
        $titik = Geo::parse($lokasi);

        if ($cabang === null) {
            return null;
        }

        if ($titik === null) {
            throw new AturanBisnisException("Lokasi Anda belum terbaca. Izinkan akses lokasi di browser, lalu coba lagi. Absen di {$cabang->name} hanya bisa dalam radius {$cabang->attendance_radius_m} m.");
        }

        $jarak = Geo::distanceMeters($titik[0], $titik[1], $cabang->latitude, $cabang->longitude);
        if ($jarak > $cabang->attendance_radius_m) {
            throw new AturanBisnisException("Anda berada sekitar {$jarak} m dari {$cabang->name}, di luar radius absen {$cabang->attendance_radius_m} m.");
        }

        return $jarak;
    }

    private function periksaSelfie(?UploadedFile $selfie): void
    {
        if ($selfie === null && setting('sdm.attendance.require_selfie', '0') === '1') {
            throw new AturanBisnisException('Ambil foto selfie dulu untuk absen.');
        }
    }

    /** Scan sebelum (jam pulang shift malam kemarin + 4 jam) dihitung ke tanggal kemarin. */
    private function tanggalScan(Employee $employee, CarbonImmutable $waktu): string
    {
        $kemarin = $waktu->subDay()->startOfDay();
        $hari = $this->calendar->day($employee, $kemarin);
        $shift = $hari['shift'];

        if ($hari['type'] === 'work' && $shift?->lintasHari()) {
            $batas = $waktu->startOfDay()->setTimeFromTimeString($shift->jam_pulang)->addHours(4);
            if ($waktu->lessThan($batas)) {
                return $kemarin->toDateString();
            }
        }

        return $waktu->toDateString();
    }

    private function gabung(CarbonInterface $tanggal, ?string $jam): ?CarbonInterface
    {
        return $jam === null || $jam === '' ? null : $tanggal->copy()->setTimeFromTimeString($jam);
    }

    /** Jam pulang lebih awal dari jam masuk = keesokan harinya (shift malam). */
    private function gabungPulang(CarbonInterface $tanggal, ?string $masuk, ?string $pulang): ?CarbonInterface
    {
        $hasil = $this->gabung($tanggal, $pulang);
        $awal = $this->gabung($tanggal, $masuk);

        return $hasil !== null && $awal !== null && $hasil->lessThan($awal) ? $hasil->copy()->addDay() : $hasil;
    }

    private function pesanSudahAda(Absensi $absensi): string
    {
        if ($absensi->clock_in !== null) {
            return 'Anda sudah absen masuk hari ini pukul '.$absensi->clock_in->format('H:i').'.';
        }

        return "Hari ini Anda sudah tercatat {$absensi->status->label()}. Hubungi admin bila perlu diubah.";
    }
}
