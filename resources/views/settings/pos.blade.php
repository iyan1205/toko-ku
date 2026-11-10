{{-- Pengaturan Kasir: aturan yang berlaku di layar Kasir. --}}
@php
    $bolehUbah = auth()->user()->can('settings.pos.update');
@endphp

<x-app-layout>
    <x-slot name="header">Kasir</x-slot>
    <x-slot name="description">Aturan yang berlaku di layar Kasir untuk semua kasir.</x-slot>

    <form method="POST" action="{{ route('settings.pos.update') }}" class="grid gap-8">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Sesi kasir" description="Sesi dibuka dengan modal awal dan ditutup dengan menghitung uang di laci, supaya setoran tiap kasir bisa dicocokkan.">
                <x-ui.checkbox name="require_session" label="Wajib buka sesi kasir sebelum menjual" :checked="$requireSession" unchecked-value="0"
                               hint="Bila tidak dicentang, kasir boleh menjual tanpa sesi. Penjualan tanpa sesi memakai gudang bawaan cabang yang dipilih di topbar." />

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan pengaturan kasir</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>
</x-app-layout>
