{{--
    Kerangka "sedang memuat". type: text | card | table | avatar. lines = jumlah baris (text/table).
    Biasanya dipakai bersama Alpine: <div x-show="memuat"><x-ui.skeleton type="table" lines="5" /></div>
--}}
@props(['type' => 'text', 'lines' => 3])

<div role="status" {{ $attributes }}>
    <span class="sr-only">Memuat…</span>
    <div class="animate-pulse" aria-hidden="true">
        @switch($type)
            @case('avatar')
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-slate-200"></div>
                    <div class="grid flex-1 gap-2">
                        <div class="h-3 w-1/3 rounded bg-slate-200"></div>
                        <div class="h-3 w-1/2 rounded bg-slate-100"></div>
                    </div>
                </div>
                @break

            @case('card')
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="h-3 w-1/3 rounded bg-slate-200"></div>
                    <div class="mt-4 h-7 w-1/2 rounded bg-slate-200"></div>
                    <div class="mt-4 h-3 w-2/3 rounded bg-slate-100"></div>
                </div>
                @break

            @case('table')
                <div class="grid gap-3">
                    <div class="h-8 rounded bg-slate-200"></div>
                    @for ($i = 0; $i < (int) $lines; $i++)
                        <div class="h-6 rounded bg-slate-100"></div>
                    @endfor
                </div>
                @break

            @default
                <div class="grid gap-2">
                    @for ($i = 0; $i < (int) $lines; $i++)
                        <div @class(['h-3 rounded bg-slate-200', 'w-2/3' => $i === (int) $lines - 1, 'w-full' => $i !== (int) $lines - 1])></div>
                    @endfor
                </div>
        @endswitch
    </div>
</div>
