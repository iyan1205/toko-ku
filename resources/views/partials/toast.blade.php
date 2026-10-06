@php
    $toast = session('success')
        ? ['tipe' => 'success', 'pesan' => session('success')]
        : (session('error') ? ['tipe' => 'error', 'pesan' => session('error')] : null);
@endphp

@if ($toast)
    <div x-data="{ tampil: true }" x-init="setTimeout(() => tampil = false, 5000)" x-show="tampil" x-cloak
         x-transition.opacity role="status" aria-live="polite"
         class="fixed right-4 top-4 z-50 flex max-w-sm items-start gap-3 rounded-md border px-4 py-3 text-sm shadow-lg
                {{ $toast['tipe'] === 'success' ? 'border-teal-200 bg-teal-50 text-teal-900' : 'border-red-200 bg-red-50 text-red-900' }}">
        <p class="flex-1">{{ $toast['pesan'] }}</p>
        <button type="button" @click="tampil = false" class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Tutup">&times;</button>
    </div>
@endif
