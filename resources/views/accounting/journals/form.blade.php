{{--
    Jurnal manual baru atau ubah draf. Total debit harus sama dengan total kredit; server memeriksa
    ulang dengan bilangan bulat (JournalService). Baris tanpa nilai diabaikan. Cost center per baris
    opsional. "Simpan & posting" langsung memposting, atau mengajukan persetujuan bila ada alur yang cocok.
--}}
@php
    $edit = $journal->exists;
    $barisAwal = $edit
        ? $journal->lines->map(fn ($l) => [
            'account_id' => $l->account_id,
            'description' => $l->description,
            'cost_center_id' => $l->cost_center_id,
            'debit' => (float) $l->debit > 0 ? $l->debit : '',
            'credit' => (float) $l->credit > 0 ? $l->credit : '',
        ])->all()
        : [
            ['account_id' => '', 'description' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => ''],
            ['account_id' => '', 'description' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => ''],
        ];
    $baris = collect(old('lines', $barisAwal))->map(fn ($l) => [
        'account_id' => (string) ($l['account_id'] ?? ''),
        'description' => (string) ($l['description'] ?? ''),
        'cost_center_id' => (string) ($l['cost_center_id'] ?? ''),
        'debit' => (string) ($l['debit'] ?? ''),
        'credit' => (string) ($l['credit'] ?? ''),
    ])->values()->all();
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draf '.$journal->number : 'Jurnal manual' }}</x-slot>
    <x-slot name="description">Misal setoran modal, pembayaran beban, atau koreksi. Penjualan, pembelian, dan mutasi stok sudah dijurnal otomatis.</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route('journals.update', $journal) : route('journals.store') }}" class="grid gap-6"
          x-data="@include('accounting.partials.journal-lines-state', ['lines' => $baris])"
          x-on:submit="kirim = true">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input name="journal_date" label="Tanggal" type="date" required :value="($journal->journal_date ?? now())->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.input name="description" label="Keterangan" required maxlength="255" class="sm:col-span-3" :value="$journal->description" placeholder="Misal: Setoran modal awal pemilik" />
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-4">
                <x-ui.input name="auto_reverse_on" label="Balik otomatis pada" type="date" :value="$journal->auto_reverse_on?->format('Y-m-d')"
                            hint="Opsional, untuk jurnal penyesuaian (misal akrual) yang dibalik di awal bulan berikutnya." />
            </div>
        </x-ui.card>

        @include('accounting.partials.journal-lines')

        <div class="flex flex-wrap items-center justify-end gap-2">
            <p class="mr-auto text-xs text-slate-500">Bila jurnal memerlukan persetujuan (Pengaturan → Alur Persetujuan), jurnal diajukan otomatis dan masuk saldo setelah disetujui.</p>
            <x-ui.button :href="$edit ? route('journals.show', $journal) : route('journals.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="draft" variant="secondary" x-bind:disabled="kirim || !seimbang">Simpan draf</x-ui.button>
            <x-ui.button type="submit" name="aksi" value="post" icon="check" x-bind:disabled="kirim || !seimbang">Simpan &amp; posting</x-ui.button>
        </div>
    </form>
</x-app-layout>
