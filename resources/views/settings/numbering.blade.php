{{-- Penomoran dokumen. Contoh nomor dihitung di browser dari pola; aturan sahnya pola dicek di server. --}}
@php
    $bolehUbah = auth()->user()->can('settings.numbering.update');
    $hariIni = now();
    $tanggal = ['YYYY' => $hariIni->format('Y'), 'YY' => $hariIni->format('y'), 'MM' => $hariIni->format('m'), 'DD' => $hariIni->format('d')];
@endphp

<x-app-layout>
    <x-slot name="header">Penomoran Dokumen</x-slot>
    <x-slot name="description">Format nomor untuk dokumen baru. Nomor dokumen yang sudah ada tidak berubah.</x-slot>

    <form method="POST" action="{{ route('settings.numbering.update') }}" class="grid gap-6"
          x-data="{
              tanggal: @js($tanggal),
              contoh(pola) {
                  return String(pola || '').replace(/\{(YYYY|YY|MM|DD|#{3,8})\}/g, (_, t) => this.tanggal[t] ?? '1'.padStart(t.length, '0'));
              },
          }">
        @csrf
        @method('PUT')

        <x-ui.card>
            <p class="text-sm font-medium text-slate-900">Penanda yang bisa dipakai</p>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['{YYYY}' => 'Tahun 4 digit ('.$tanggal['YYYY'].')', '{YY}' => 'Tahun 2 digit ('.$tanggal['YY'].')', '{MM}' => 'Bulan ('.$tanggal['MM'].')', '{DD}' => 'Tanggal ('.$tanggal['DD'].')', '{####}' => 'Nomor urut, jumlah # = jumlah digit (3–8)'] as $penanda => $arti)
                    <div class="flex gap-3">
                        <dt><code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{{ $penanda }}</code></dt>
                        <dd class="text-slate-500">{{ $arti }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-sm text-slate-500">
                Contoh: <code class="font-mono text-xs text-slate-700">INV/{YYYY}/{MM}/{####}</code> menghasilkan
                <span class="font-mono text-xs text-slate-700">INV/{{ $tanggal['YYYY'] }}/{{ $tanggal['MM'] }}/0001</span>, direset setiap bulan.
            </p>
        </x-ui.card>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th>Pola nomor</x-ui.table.th>
                <x-ui.table.th>Nomor urut kembali ke 1</x-ui.table.th>
                <x-ui.table.th>Contoh nomor</x-ui.table.th>
            </x-slot:head>

            @foreach ($documents as $doc)
                @php
                    $key = $doc['type']->value;
                    $pola = old("formats.{$key}.pattern", $doc['format']['pattern']);
                    $reset = old("formats.{$key}.reset", $doc['format']['reset']->value);
                    $error = $errors->first("formats.{$key}.pattern") ?: $errors->first("formats.{$key}.reset");
                @endphp
                <tr x-data="{ pola: @js($pola) }" class="align-top">
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $doc['type']->label() }}</p>
                        <p class="mt-0.5 font-mono text-xs text-slate-400">Bawaan {{ $doc['type']->defaultPattern() }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <label for="pola-{{ $key }}" class="sr-only">Pola nomor {{ $doc['type']->label() }}</label>
                        <input type="text" id="pola-{{ $key }}" name="formats[{{ $key }}][pattern]" x-model="pola" value="{{ $pola }}"
                               maxlength="40" required spellcheck="false" autocomplete="off" @disabled(! $bolehUbah)
                               @if ($error) aria-invalid="true" aria-describedby="pola-{{ $key }}-error" @endif
                               @class([
                                   'block w-full min-w-[14rem] rounded-lg border font-mono text-sm shadow-sm focus:outline-none focus:ring-1 disabled:bg-slate-50 disabled:text-slate-500',
                                   'border-slate-300 focus:border-primary-500 focus:ring-primary-500' => ! $error,
                                   'border-danger-400 focus:border-danger-500 focus:ring-danger-500' => $error,
                               ])>
                        @if ($error)
                            <p id="pola-{{ $key }}-error" class="mt-1.5 max-w-sm text-sm text-danger-600">{{ $error }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <label for="reset-{{ $key }}" class="sr-only">Reset nomor {{ $doc['type']->label() }}</label>
                        <select id="reset-{{ $key }}" name="formats[{{ $key }}][reset]" @disabled(! $bolehUbah)
                                class="block w-full min-w-[9rem] rounded-lg border border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500">
                            @foreach ($resets as $r)
                                <option value="{{ $r->value }}" @selected($reset === $r->value)>{{ $r->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <span class="whitespace-nowrap font-mono text-sm text-slate-700" x-text="contoh(pola)">{{ $doc['example'] }}</span>
                    </x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>

        <p class="text-sm text-slate-500">
            Bila pola diganti di tengah periode, nomor urut tetap berlanjut. Nomor yang sudah pernah dipakai selalu dilewati, jadi tidak ada nomor ganda.
        </p>

        @if ($bolehUbah)
            <div class="flex justify-end">
                <x-ui.button type="submit">Simpan format nomor</x-ui.button>
            </div>
        @endif
    </form>
</x-app-layout>
