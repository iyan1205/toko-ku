{{--
    Notifikasi sementara di pojok kanan atas. Pasang SEKALI di layout.
    - Dari server: session flash 'success', 'error', 'warning', 'info' (redirect()->with('success', '...')).
    - Dari JavaScript: $dispatch('toast', { type: 'success', message: 'Tersimpan.' })
      type: success | danger | warning | info. Hilang sendiri setelah 5 detik (danger 8 detik).
--}}
@php
    $awal = collect(['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'])
        ->filter(fn ($type, $key) => filled(session($key)))
        ->map(fn ($type, $key) => ['type' => $type, 'message' => (string) session($key)])
        ->values();
@endphp

<div x-data="{
        items: [],
        nextId: 0,
        add(toast) {
            const id = this.nextId++;
            const type = toast.type ?? 'info';
            this.items.push({ id, type, message: toast.message });
            setTimeout(() => this.remove(id), toast.timeout ?? (type === 'danger' ? 8000 : 5000));
        },
        remove(id) {
            this.items = this.items.filter(item => item.id !== id);
        },
     }"
     x-init="@js($awal).forEach(toast => add(toast))"
     x-on:toast.window="add($event.detail)"
     aria-live="polite"
     {{ $attributes->class('pointer-events-none fixed inset-x-4 top-4 z-[60] flex flex-col items-end gap-2 sm:left-auto sm:right-4 print:hidden') }}>
    <template x-for="item in items" :key="item.id">
        <div x-transition.opacity.duration.200ms
             x-bind:role="item.type === 'danger' ? 'alert' : 'status'"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm shadow-float">
            <span x-show="item.type === 'success'"><x-ui.icon name="check-circle" variant="m" class="h-5 w-5 text-success-500" /></span>
            <span x-show="item.type === 'danger'"><x-ui.icon name="x-circle" variant="m" class="h-5 w-5 text-danger-500" /></span>
            <span x-show="item.type === 'warning'"><x-ui.icon name="exclamation-triangle" variant="m" class="h-5 w-5 text-warning-500" /></span>
            <span x-show="item.type === 'info'"><x-ui.icon name="information-circle" variant="m" class="h-5 w-5 text-info-500" /></span>
            <p class="min-w-0 flex-1 text-slate-700" x-text="item.message"></p>
            <button type="button" x-on:click="remove(item.id)" class="-m-1 shrink-0 rounded p-1 text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" aria-label="Tutup">
                <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
