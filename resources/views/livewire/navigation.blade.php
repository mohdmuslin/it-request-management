{{--
    Sidebar navigation.

    Desktop: a sticky column beside the content.
    Mobile: hidden by default, opened from the header button. The toggle lives in
    this component so the state does not have to be threaded through the layout.
--}}
<nav x-data="{ open: false }" @toggle-nav.window="open = ! open"
     class="flex-none lg:sticky lg:top-[57px] lg:h-[calc(100vh-57px)] lg:w-64 lg:overflow-y-auto">

    {{-- Mobile overlay --}}
    <div x-show="open" x-transition.opacity @click="open = false"
         class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" aria-hidden="true"></div>

    <div :class="open ? 'translate-x-0' : '-translate-x-full'"
         class="fixed inset-y-0 left-0 z-40 w-64 transform overflow-y-auto border-r border-slate-200 bg-white transition-transform duration-200 lg:static lg:z-auto lg:w-64 lg:translate-x-0">

        <div class="p-3">
            @foreach ($this->groups() as $group)
                <div class="mb-4">
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        {{ $group['label'] }}
                    </p>

                    <ul class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate
                                   @class([
                                       'flex items-center rounded-lg px-3 py-2 text-sm',
                                       'bg-indigo-50 font-medium text-indigo-700' => $item['active'],
                                       'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $item['active'],
                                   ])
                                   @if ($item['active']) aria-current="page" @endif>
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{--
            Why this user sees what they see.

            A silently narrower view is how someone concludes the system has lost
            their request rather than that they cannot see it.
        --}}
        <div class="border-t border-slate-100 p-3">
            <div class="rounded-lg bg-slate-50 p-3">
                <p class="text-[11px] font-semibold text-slate-600">Your scope</p>
                <p class="mt-1 text-[11px] leading-snug text-slate-500">{{ $this->scopeNote() }}</p>
            </div>
        </div>
    </div>
</nav>
