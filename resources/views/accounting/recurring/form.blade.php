{{-- Templat jurnal berulang baru / ubah. Baris jurnal memakai partial yang sama dengan jurnal manual. --}}
@use('App\Models\RecurringJournal')
@php
    $edit = $template->exists;
    $barisAwal = $edit
        ? $template->lines->map(fn ($l) => ['account_id' => $l->account_id, 'description' => $l->description, 'cost_center_id' => $l->cost_center_id, 'debit' => (float) $l->debit > 0 ? $l->debit : '', 'credit' => (float) $l->credit > 0 ? $l->credit : ''])->all()
        : array_fill(0, 2, ['account_id' => '', 'description' => '', 'cost_center_id' => '', 'debit' => '', 'credit' => '']);
    $baris = collect(old('lines', $barisAwal))->map(fn ($l) => [
        'account_id' => (string) ($l['account_id'] ?? ''),
        'description' => (string) ($l['description'] ?? ''),
        'cost_center_id' => (string) ($l['cost_center_id'] ?? ''),
        'debit' => (string) ($l['debit'] ?? ''),
        'credit' => (string) ($l['credit'] ?? ''),
    ])->values()->all();
    $tanggal = collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => "Tanggal {$d}"])->put(31, 'Tanggal terakhir bulan itu');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah jurnal berulang' : 'Jurnal berulang baru' }}</x-slot>
    <x-slot name="description">Templat untuk jurnal yang sama setiap periode, misal sewa, cicilan, atau penyusutan. Jurnal dibuat otomatis oleh scheduler pada tanggal jadwal.</x-slot>

    @if ($errors->has('lines'))
        <x-ui.alert type="danger" class="mb-6">{{ $errors->first('lines') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route('recurring-journals.update', $template) : route('recurring-journals.store') }}" class="grid gap-6"
          x-data="@include('accounting.partials.journal-lines-state', ['lines' => $baris])"
          x-on:submit="kirim = true">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="name" label="Nama templat" required maxlength="100" :value="$template->name" placeholder="Misal: Sewa ruko bulanan" />
                <x-ui.input name="description" label="Keterangan jurnal" required maxlength="200" :value="$template->description" placeholder="Misal: Beban sewa ruko"
                            hint="Bulannya ditambahkan otomatis, misal &quot;Beban sewa ruko (November 2026)&quot;." />
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-4">
                <x-ui.select name="interval_months" label="Frekuensi" required :options="RecurringJournal::INTERVALS" :selected="$template->interval_months" />
                <x-ui.select name="day_of_month" label="Pada" required :options="$tanggal" :selected="$template->day_of_month" />
                <x-ui.input name="start_on" label="Mulai" type="date" required :value="$template->start_on?->format('Y-m-d')" />
                <x-ui.input name="end_on" label="Sampai" type="date" :value="$template->end_on?->format('Y-m-d')" hint="Kosongkan bila tanpa batas." />
            </div>
            <div class="mt-5 grid gap-3">
                <x-ui.checkbox name="auto_post" label="Langsung diposting" :checked="$template->auto_post ?? true" unchecked-value="0"
                               hint="Bila tidak dicentang, jurnal dibuat sebagai draf untuk diperiksa dulu. Alur persetujuan jurnal tetap berlaku." />
                <x-ui.checkbox name="is_active" label="Aktif" :checked="$template->is_active ?? true" unchecked-value="0" />
            </div>
        </x-ui.card>

        @include('accounting.partials.journal-lines')

        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-ui.button :href="$edit ? route('recurring-journals.show', $template) : route('recurring-journals.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check" x-bind:disabled="kirim || !seimbang">{{ $edit ? 'Simpan perubahan' : 'Simpan templat' }}</x-ui.button>
        </div>
    </form>
</x-app-layout>
