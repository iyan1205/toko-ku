{{-- Impor massal satu jenis data: unduh templat, unggah, lalu pratinjau sebelum diproses. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Impor {{ $definition->title() }}</x-slot>
    <x-slot name="description">Tambah atau perbarui banyak data sekaligus dari Excel/CSV. Data baru berubah setelah Anda memeriksa pratinjau dan menekan Proses.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" :href="route($definition->indexRoute())">Kembali</x-ui.button>
        <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('imports.'.$type.'.template')">Unduh templat</x-ui.button>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid content-start gap-6 lg:col-span-2">
            <x-ui.card title="Unggah berkas">
                <ol class="mb-5 grid gap-1 text-sm text-slate-600">
                    <li><span class="font-semibold text-slate-900">1.</span> Unduh templat, isi lembar <span class="font-medium">Data</span> mulai baris 2 (lembar Petunjuk menjelaskan setiap kolom).</li>
                    <li><span class="font-semibold text-slate-900">2.</span> Unggah berkasnya. Setiap baris diperiksa dan hasilnya ditampilkan dulu.</li>
                    <li><span class="font-semibold text-slate-900">3.</span> Proses baris yang benar. Baris yang salah dilewati dan bisa diunduh untuk diperbaiki.</li>
                </ol>
                <form method="POST" action="{{ route('imports.'.$type.'.preview') }}" enctype="multipart/form-data" class="grid gap-4"
                      x-data="{ kirim: false }" x-on:submit="kirim = true">
                    @csrf
                    <x-ui.input name="file" type="file" label="Berkas Excel atau CSV" required accept=".xlsx,.xls,.csv"
                                :hint="'Maksimal '.$definition->maxRows().' baris, ukuran '.Format::jumlah($maxKb / 1024).' MB. '.$definition->matchingRule()" />
                    <div class="flex justify-end">
                        <x-ui.button type="submit" icon="arrow-up-tray" x-bind:disabled="kirim">Unggah &amp; periksa</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <h2 class="text-sm font-semibold text-slate-900">Kolom templat</h2>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Kolom</x-ui.table.th>
                    <x-ui.table.th>Keterangan</x-ui.table.th>
                    <x-ui.table.th>Contoh</x-ui.table.th>
                </x-slot:head>
                @foreach ($definition->columns() as $kolom)
                    <tr>
                        <x-ui.table.td>
                            <span class="font-medium text-slate-900">{{ $kolom['label'] }}</span>
                            @if ($kolom['required'] ?? false)
                                <x-ui.badge color="danger" class="ml-1">Wajib</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td muted>{{ $kolom['hint'] ?? '—' }}</x-ui.table.td>
                        <x-ui.table.td muted><span class="font-mono text-xs">{{ $kolom['example'] ?? '' }}</span></x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </div>

        <x-ui.card title="Riwayat impor saya" :padding="false">
            <ul class="divide-y divide-slate-100">
                @forelse ($history as $b)
                    <li>
                        <a href="{{ route('imports.'.$type.'.show', $b) }}" class="block px-5 py-3 hover:bg-slate-50">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $b->file_name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ Format::tanggal($b->created_at, true) }} · {{ $b->statusLabel() }} ·
                                @if ($b->isPreviewed())
                                    {{ $b->valid_rows }} benar, {{ $b->invalid_rows }} salah
                                @else
                                    {{ $b->created_count }} baru, {{ $b->updated_count }} diperbarui
                                @endif
                            </p>
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-slate-500">Belum ada impor.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</x-app-layout>
