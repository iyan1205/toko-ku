{{-- Metode pembayaran di kasir: urutan (tombol naik/turun), nama tampilan, aktif/nonaktif. --}}
@php
    $bolehUbah = auth()->user()->can('settings.payment-methods.update');
    $catatan = [
        'cash' => 'Kasir mengisi uang diterima; kembalian dihitung otomatis.',
        'receivable' => 'Dicatat sebagai piutang. Hanya untuk pelanggan terdaftar.',
    ];
    $baris = old('methods', $methods->map(fn ($m) => [
        'code' => $m['method']->value,
        'label' => $m['label'],
        'is_active' => $m['is_active'],
    ])->all());
    $bawaan = collect(\App\Enums\PaymentMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->defaultLabel()]);
@endphp

<x-app-layout>
    <x-slot name="header">Metode Pembayaran</x-slot>
    <x-slot name="description">Metode yang muncul di kasir, urutannya, dan nama yang tercetak di struk.</x-slot>

    <form method="POST" action="{{ route('settings.payment-methods.update') }}" class="grid gap-6"
          x-data="{
              baris: @js(array_map(fn ($b) => ['code' => $b['code'], 'label' => $b['label'], 'is_active' => filter_var($b['is_active'], FILTER_VALIDATE_BOOLEAN)], $baris)),
              bawaan: @js($bawaan),
              catatan: @js($catatan),
              pindah(i, arah) {
                  const j = i + arah;
                  if (j < 0 || j >= this.baris.length) return;
                  [this.baris[i], this.baris[j]] = [this.baris[j], this.baris[i]];
              },
          }">
        @csrf
        @method('PUT')

        @error('methods')
            <x-ui.alert type="danger">{{ $message }}</x-ui.alert>
        @enderror

        <x-ui.card :padding="false">
            <ol class="divide-y divide-slate-100">
                <template x-for="(m, i) in baris" :key="m.code">
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                        <input type="hidden" x-bind:name="`methods[${i}][code]`" x-bind:value="m.code">
                        <input type="hidden" x-bind:name="`methods[${i}][is_active]`" x-bind:value="m.is_active ? 1 : 0">

                        <div class="flex items-center gap-3 sm:w-64">
                            @if ($bolehUbah)
                                <div class="flex flex-col">
                                    <button type="button" x-on:click="pindah(i, -1)" x-bind:disabled="i === 0"
                                            class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                                            x-bind:aria-label="'Naikkan ' + m.label">
                                        <x-ui.icon name="chevron-up" variant="m" class="h-4 w-4" />
                                    </button>
                                    <button type="button" x-on:click="pindah(i, 1)" x-bind:disabled="i === baris.length - 1"
                                            class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                                            x-bind:aria-label="'Turunkan ' + m.label">
                                        <x-ui.icon name="chevron-down" variant="m" class="h-4 w-4" />
                                    </button>
                                </div>
                            @endif
                            <span class="w-5 text-right text-sm tabular-nums text-slate-400" x-text="i + 1"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900" x-text="bawaan[m.code]"></p>
                                <p class="text-xs text-slate-500" x-show="catatan[m.code]" x-text="catatan[m.code]"></p>
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <label class="sr-only" x-bind:for="'label-' + m.code" x-text="'Nama tampilan ' + bawaan[m.code]"></label>
                            <input type="text" x-bind:id="'label-' + m.code" x-bind:name="`methods[${i}][label]`" x-model="m.label"
                                   maxlength="40" required @disabled(! $bolehUbah)
                                   class="block w-full rounded-lg border border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500">
                        </div>

                        <label class="inline-flex shrink-0 cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" x-model="m.is_active" @disabled(! $bolehUbah)
                                   class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                            <span x-text="m.is_active ? 'Aktif' : 'Nonaktif'"></span>
                        </label>
                    </li>
                </template>
            </ol>
        </x-ui.card>

        @foreach ($errors->get('methods.*') as $pesan)
            <p class="text-sm text-danger-600">{{ $pesan[0] }}</p>
        @endforeach

        <p class="text-sm text-slate-500">
            Metode nonaktif tidak bisa dipilih di kasir, tetapi tetap tampil di riwayat dan laporan transaksi lama.
            Pembayaran ke supplier tidak terpengaruh.
        </p>

        @if ($bolehUbah)
            <div class="flex justify-end">
                <x-ui.button type="submit">Simpan metode pembayaran</x-ui.button>
            </div>
        @endif
    </form>
</x-app-layout>
