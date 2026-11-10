<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Tanda hidup queue worker dan scheduler (docs/DEPLOY.md):
 *   - worker menulis queue.heartbeat saat berputar menunggu job (Queue::looping, AppServiceProvider),
 *   - scheduler menulis schedule.heartbeat setiap menit (routes/console.php).
 * Dipakai halaman Pengaturan → Sistem, dan ekspor besar: hanya lewat queue bila worker hidup.
 */
final class QueueHealth
{
    public const WORKER_KEY = 'queue.heartbeat';

    public const SCHEDULER_KEY = 'schedule.heartbeat';

    /** Worker dianggap hidup bila memberi tanda dalam 5 menit terakhir. */
    private const WORKER_GRACE_MINUTES = 5;

    private const SCHEDULER_GRACE_MINUTES = 3;

    private static ?int $lastBeat = null;

    public static function workerBeat(): void
    {
        // Paling sering sekali per 30 detik agar tidak menulis cache di setiap putaran worker.
        if (self::$lastBeat !== null && time() - self::$lastBeat < 30) {
            return;
        }

        self::$lastBeat = time();
        Cache::put(self::WORKER_KEY, now()->toIso8601String(), now()->addDay());
    }

    public static function schedulerBeat(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->toIso8601String(), now()->addDay());
    }

    public static function workerLastSeen(): ?CarbonImmutable
    {
        return self::read(self::WORKER_KEY);
    }

    public static function schedulerLastSeen(): ?CarbonImmutable
    {
        return self::read(self::SCHEDULER_KEY);
    }

    public static function workerAlive(): bool
    {
        $seen = self::workerLastSeen();

        return $seen !== null && $seen->greaterThan(now()->subMinutes(self::WORKER_GRACE_MINUTES));
    }

    public static function schedulerAlive(): bool
    {
        $seen = self::schedulerLastSeen();

        return $seen !== null && $seen->greaterThan(now()->subMinutes(self::SCHEDULER_GRACE_MINUTES));
    }

    /** Job benar-benar dijalankan di latar belakang (bukan koneksi sync) dan ada worker yang hidup. */
    public static function canQueue(): bool
    {
        return config('queue.default') !== 'sync' && self::workerAlive();
    }

    private static function read(string $key): ?CarbonImmutable
    {
        $value = Cache::get($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
