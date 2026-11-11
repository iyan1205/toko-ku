{{-- Rekonsiliasi bank: daftar rekening koran yang diimpor dan formulir impor. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Rekonsiliasi Bank</x-slot>
    <x-slot name="description">Impor rekening koran (CSV/Excel), cocokkan mutasinya dengan pencatatan di buku, lalu kunci bila sudah sesuai.</x-slot>

    @can('accounting.reconciliation.create')
        <form method="POST" action="{{ route('bank-reconciliations.store') }}" enctype="multipart/form-data" class="mb-8">
            @csrf
            <x-ui.form-section title="Impor rekening koran" description="Baris judul: Tanggal, Keterangan, Referensi, Masuk, Keluar, Saldo (atau satu kolom Jumlah bertanda). Mutasi di luar periode ditolak.">
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.select name="cash_bank_account_id" label="Rekening" required :options="$rekening->mapWithKeys(fn ($r) => [$r->id => $r->label()])" placeholder="Pilih rekening" />
                    <x-ui.input name="period_start" label="Awal periode" type="date" required :value="now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d')" />
                    <x-ui.input name="period_end" label="Akhir periode" type="date" required :value="now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                </div>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.input name="opening_balance" label="Saldo awal rekening koran (Rp)" type="number" step="0.01" />
                    <x-ui.input name="closing_balance" label="Saldo akhir rekening koran (Rp)" type="number" step="0.01" required />
                    <x-ui.field for="f-file" label="Berkas" required>
                        <input id="f-file" type="file" name="file" required accept=".xlsx,.xls,.csv"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                        @error('file')
                            <p class="mt-1.5 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </x-ui.field>
                </div>
                <x-slot:footer>
                    <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('bank-reconciliations.template')">Unduh templat</x-ui.button>
                    <x-ui.button type="submit" icon="arrow-up-tray">Impor &amp; cocokkan</x-ui.button>
                </x-slot:footer>
            </x-ui.form-section>
        </form>
    @endcan

    <x-ui.table :paginator="$statements">
        <x-slot:head>
            <x-ui.table.th>Rekening</x-ui.table.th>
            <x-ui.table.th>Periode</x-ui.table.th>
            <x-ui.table.th>Cocok</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Saldo akhir koran</x-ui.table.th>
        </x-slot:head>
        @forelse ($statements as $s)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('bank-reconciliations.show', $s) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $s->cashBankAccount?->name }}</a>
                    @if ($s->file_name)
                        <p class="text-xs text-slate-500">{{ $s->file_name }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($s->period_start) }} – {{ Format::tanggal($s->period_end) }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $s->matched_count }} / {{ $s->lines_count }} mutasi</x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$s->isFinalized() ? 'success' : ($s->matched_count === $s->lines_count ? 'info' : 'warning')" dot>
                        {{ $s->isFinalized() ? 'Dikunci' : ($s->matched_count === $s->lines_count ? 'Semua cocok' : 'Belum selesai') }}
                    </x-ui.badge>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($s->closing_balance)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="document-check" title="Belum ada rekonsiliasi" description="Unduh rekening koran dari internet banking, sesuaikan dengan templat, lalu impor di atas." />
        @endforelse
    </x-ui.table>
</x-app-layout>
