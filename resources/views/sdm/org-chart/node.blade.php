{{-- Satu kotak di bagan organisasi beserta bawahannya (rekursif). $node = ['employee' => Employee, 'children' => [...]] --}}
<li x-data="{ buka: true }" x-on:org-ciut.window="buka = $event.detail" data-nama="{{ mb_strtolower($node['employee']->name.' '.$node['employee']->code.' '.($node['employee']->position?->name ?? '')) }}">
    <div class="inline-flex min-w-[11rem] max-w-[14rem] flex-col items-center gap-1 rounded-lg border bg-white px-3 py-2 shadow-sm transition"
         x-bind:class="cocok($el.closest('li').dataset.nama) ? 'border-primary-500 ring-2 ring-primary-200' : 'border-slate-200'">
        @if ($node['employee']->photo_path && $bolehDetail)
            <img src="{{ route('employees.photo', $node['employee']) }}" alt="" class="h-10 w-10 rounded-full object-cover" loading="lazy">
        @else
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-700" aria-hidden="true">{{ $node['employee']->initials() }}</span>
        @endif
        @if ($bolehDetail)
            <a href="{{ route('employees.show', $node['employee']) }}" class="text-sm font-semibold leading-tight text-slate-900 hover:text-primary-700 hover:underline">{{ $node['employee']->name }}</a>
        @else
            <span class="text-sm font-semibold leading-tight text-slate-900">{{ $node['employee']->name }}</span>
        @endif
        <span class="text-xs leading-tight text-slate-500">{{ $node['employee']->position?->name ?? 'Tanpa jabatan' }}</span>
        @if ($node['employee']->department)
            <span class="text-[11px] leading-tight text-slate-400">{{ $node['employee']->department->name }}</span>
        @endif
        @if ($node['children'] !== [])
            <button type="button" x-on:click="buka = ! buka" x-bind:aria-expanded="buka"
                    class="mt-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 hover:bg-slate-200">
                <x-ui.icon name="chevron-down" variant="c" class="h-3 w-3 transition" x-bind:class="buka ? '' : '-rotate-90'" />
                {{ count($node['children']) }} bawahan
            </button>
        @endif
    </div>
    @if ($node['children'] !== [])
        <ul x-show="buka">
            @foreach ($node['children'] as $child)
                @include('sdm.org-chart.node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
