<?php

namespace App\Services\Settings;

use App\Enums\PaymentMethod;
use App\Models\PaymentMethodSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metode pembayaran di kasir: nama tampilan, aktif/nonaktif, dan urutan (tabel payment_methods).
 *
 * Nama tampilan dipakai di semua tempat (kasir, struk, riwayat, laporan) lewat
 * PaymentMethod::label(). Aktif/nonaktif dan urutan hanya berlaku untuk kasir;
 * riwayat dan laporan tetap mengenali metode yang sudah dinonaktifkan.
 *
 * Didaftarkan scoped di AppServiceProvider: dibaca sekali per request.
 */
class PaymentMethodService
{
    public const CACHE_KEY = 'settings.payment_methods';

    /** @var Collection<int, array{method: PaymentMethod, label: string, is_active: bool}>|null */
    private ?Collection $options = null;

    /**
     * Semua metode, terurut sesuai pengaturan.
     *
     * @return Collection<int, array{method: PaymentMethod, label: string, is_active: bool}>
     */
    public function all(): Collection
    {
        return $this->options ??= $this->load();
    }

    /** @return list<PaymentMethod> metode aktif di kasir, terurut */
    public function forSales(): array
    {
        return $this->all()->where('is_active', true)->pluck('method')->values()->all();
    }

    public function label(PaymentMethod $method): string
    {
        return $this->all()->firstWhere('method', $method)['label'] ?? $method->defaultLabel();
    }

    /**
     * Simpan pengaturan. Urutan baris = urutan tampil di kasir.
     *
     * @param  list<array{code: string, label: string, is_active: bool}>  $rows
     */
    public function update(array $rows, User $actor): void
    {
        $before = $this->describe($this->all());

        DB::transaction(function () use ($rows) {
            foreach (array_values($rows) as $position => $row) {
                PaymentMethodSetting::query()->updateOrCreate(
                    ['code' => $row['code']],
                    ['label' => trim($row['label']), 'is_active' => (bool) $row['is_active'], 'sort_order' => $position + 1],
                );
            }
        });

        $this->flush();

        $after = $this->describe($this->all());
        $changed = array_keys(array_diff_assoc($after, $before));
        if ($changed !== [] || array_keys($after) !== array_keys($before)) {
            activity('pengaturan')->causedBy($actor)->event('updated')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('Metode pembayaran diubah');
        }
    }

    public function flush(): void
    {
        $this->options = null;
        Cache::forget(self::CACHE_KEY);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return Collection<int, array{method: PaymentMethod, label: string, is_active: bool}> */
    private function load(): Collection
    {
        /** @var array<string, array{label: string, is_active: bool, sort_order: int}> $rows */
        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => PaymentMethodSetting::query()->get()
            ->mapWithKeys(fn (PaymentMethodSetting $row) => [$row->code->value => [
                'label' => $row->label,
                'is_active' => $row->is_active,
                'sort_order' => $row->sort_order,
            ]])
            ->all());

        // Metode yang belum punya baris (misal metode baru di enum) tampil aktif di urutan akhir.
        return collect(PaymentMethod::cases())
            ->map(fn (PaymentMethod $method, int $index) => [
                'method' => $method,
                'label' => $rows[$method->value]['label'] ?? $method->defaultLabel(),
                'is_active' => $rows[$method->value]['is_active'] ?? true,
                'sort' => $rows[$method->value]['sort_order'] ?? 1000 + $index,
            ])
            ->sortBy('sort')
            ->map(fn (array $option) => collect($option)->except('sort')->all())
            ->values();
    }

    /**
     * @param  Collection<int, array{method: PaymentMethod, label: string, is_active: bool}>  $options
     * @return array<string, string> "Tunai" => "1. Tunai · aktif"
     */
    private function describe(Collection $options): array
    {
        return $options->values()->mapWithKeys(fn (array $option, int $index) => [
            $option['method']->defaultLabel() => sprintf('%d. %s · %s', $index + 1, $option['label'], $option['is_active'] ? 'aktif' : 'nonaktif'),
        ])->all();
    }
}
