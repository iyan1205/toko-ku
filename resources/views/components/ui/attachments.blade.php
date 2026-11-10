{{--
    Lampiran dokumen (model dengan trait HasAttachments): daftar, unggah seret-lepas (bisa banyak
    sekaligus), pratinjau gambar/PDF, unduh, dan hapus. Izin mengikuti config/attachments.php.

    <x-ui.attachments :document="$order" />
--}}
@props(['document'])

@php
    $service = app(\App\Services\Documents\AttachmentService::class);
    $user = auth()->user();
    $bolehLihat = ($izinLihat = $document->attachmentPermission('view')) && $user->can($izinLihat);
    $bolehUbah = ($izinUbah = $document->attachmentPermission('update')) && $user->can($izinUbah);
    $maxKb = $service->maxKilobytes();
    $opsi = [
        'items' => $bolehLihat ? $document->attachments()->with('uploader:id,name')->get()->map(fn ($a) => $a->toPanelArray($bolehUbah))->values() : [],
        'uploadUrl' => route('attachments.store', [$document->getMorphClass(), $document->getKey()]),
        'maxKb' => $maxKb,
        'ekstensi' => $service->allowedExtensions(),
    ];
    $maxLabel = $maxKb >= 1024 ? rtrim(rtrim(number_format($maxKb / 1024, 1, ',', '.'), '0'), ',').' MB' : $maxKb.' KB';
    $modal = 'pratinjau-lampiran-'.$document->getMorphClass().'-'.$document->getKey();
@endphp

@if ($bolehLihat)
    <x-ui.card title="Lampiran" {{ $attributes }} x-data="lampiran({{ \Illuminate\Support\Js::from($opsi) }})">
        <x-slot:actions>
            <span class="text-xs text-slate-500" x-text="items.length + ' berkas'"></span>
        </x-slot:actions>

        @if ($bolehUbah)
            <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
                   x-bind:class="seret ? 'border-primary-500 bg-primary-50' : 'border-slate-300 hover:border-slate-400 hover:bg-slate-50'"
                   x-on:dragover.prevent="seret = true" x-on:dragleave.prevent="seret = false"
                   x-on:drop.prevent="seret = false; unggah($event.dataTransfer.files)">
                <x-ui.icon name="cloud-arrow-up" class="h-7 w-7 text-slate-400" />
                <span class="text-sm font-medium text-slate-700">Seret berkas ke sini atau <span class="text-primary-600">pilih berkas</span></span>
                <span class="text-xs text-slate-500">{{ strtoupper(implode(', ', $service->allowedExtensions())) }} · maks. {{ $maxLabel }} per berkas</span>
                <input type="file" multiple class="sr-only" accept=".{{ implode(',.', $service->allowedExtensions()) }}"
                       x-on:change="unggah($event.target.files); $event.target.value = ''">
            </label>
        @endif

        <template x-for="u in antrean" :key="u.id">
            <div class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                <span class="min-w-0 truncate text-slate-700" x-text="u.name"></span>
                <span x-show="! u.error" class="shrink-0 text-xs text-slate-500">Mengunggah…</span>
                <span x-show="u.error" class="shrink-0 text-xs text-danger-600" x-text="u.error"></span>
            </div>
        </template>

        <p x-show="items.length === 0" class="mt-3 text-sm text-slate-500">Belum ada lampiran. Unggah faktur, surat jalan, bukti transfer, atau foto barang.</p>

        <ul class="mt-3 divide-y divide-slate-100" x-show="items.length > 0">
            <template x-for="item in items" :key="item.id">
                <li class="flex items-center gap-3 py-2.5">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500">
                        <x-ui.icon name="photo" class="h-5 w-5" x-show="item.kind === 'image'" />
                        <x-ui.icon name="document-text" class="h-5 w-5" x-show="item.kind === 'pdf'" />
                        <x-ui.icon name="paper-clip" class="h-5 w-5" x-show="item.kind === 'file'" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <button type="button" class="block max-w-full truncate text-left text-sm font-medium text-slate-900 hover:text-primary-700 hover:underline"
                                x-text="item.name" x-on:click="buka(item, @js($modal))"></button>
                        <p class="text-xs text-slate-500" x-text="item.size + ' · ' + (item.uploader ?? '-') + ' · ' + item.date"></p>
                    </div>
                    <a x-bind:href="item.downloadUrl" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700" x-bind:aria-label="'Unduh ' + item.name" title="Unduh">
                        <x-ui.icon name="arrow-down-tray" class="h-4 w-4" />
                    </a>
                    <button type="button" x-show="item.deleteUrl" x-on:click="hapus(item)"
                            class="rounded-lg p-1.5 text-danger-600 hover:bg-danger-50" x-bind:aria-label="'Hapus ' + item.name" title="Hapus">
                        <x-ui.icon name="trash" class="h-4 w-4" />
                    </button>
                </li>
            </template>
        </ul>

        <p x-show="galat" x-cloak class="mt-3 text-sm text-danger-600" x-text="galat"></p>
    </x-ui.card>

    <x-ui.modal :name="$modal" title="Pratinjau lampiran" max-width="2xl">
        <div x-data="{ item: null }" x-on:pratinjau-lampiran.window="if ($event.detail.modal === @js($modal)) item = $event.detail.item">
            <template x-if="item">
                <div>
                    <p class="mb-3 truncate text-sm font-medium text-slate-900" x-text="item.name"></p>
                    <template x-if="item.kind === 'image'">
                        <img x-bind:src="item.previewUrl" x-bind:alt="item.name" class="mx-auto max-h-[70vh] rounded-lg object-contain">
                    </template>
                    <template x-if="item.kind === 'pdf'">
                        <iframe x-bind:src="item.previewUrl" x-bind:title="item.name" class="h-[70vh] w-full rounded-lg border border-slate-200"></iframe>
                    </template>
                    <a x-bind:href="item.downloadUrl" class="mt-3 inline-flex text-sm font-medium text-primary-600 hover:text-primary-700">Unduh berkas</a>
                </div>
            </template>
        </div>
    </x-ui.modal>
@endif
