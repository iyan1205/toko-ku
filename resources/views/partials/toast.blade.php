@php
    $toast = session('success')
        ? ['tipe' => 'success', 'pesan' => session('success')]
        : (session('error') ? ['tipe' => 'error', 'pesan' => session('error')] : null);
@endphp

@if ($toast)
    @php($sukses = $toast['tipe'] === 'success')
    <div x-data="{ tampil: true }" x-init="setTimeout(() => tampil = false, 5000)" x-show="tampil" x-cloak
         x-transition.opacity role="status" aria-live="polite"
         class="fixed right-4 top-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-float
                {{ $sukses ? 'border-accent-300 bg-accent-50 text-accent-900' : 'border-brand-200 bg-brand-50 text-brand-900' }}">
        {{-- Ikon kecil supaya jenis pesan langsung terbaca, tidak hanya dari warna. --}}
        <span class="mt-0.5 shrink-0" aria-hidden="true">{{ $sukses ? '✓' : '!' }}</span>
        <p class="flex-1">{{ $toast['pesan'] }}</p>
        <button type="button" @click="tampil = false" class="-mr-1 -mt-0.5 shrink-0 px-1 text-lg leading-none opacity-60 hover:opacity-100" aria-label="Tutup">&times;</button>
    </div>
@endif
