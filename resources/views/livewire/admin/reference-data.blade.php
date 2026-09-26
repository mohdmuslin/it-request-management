@php
    // One block per kind, so the markup is written once rather than four times.
    $sections = [
        ['kind' => 'tier', 'title' => 'Tiers', 'rows' => $tiers],
        ['kind' => 'classification', 'title' => 'Classifications', 'rows' => $classifications],
        ['kind' => 'route', 'title' => 'Governance routes', 'rows' => $routes],
        ['kind' => 'unit', 'title' => 'Review units', 'rows' => $units],
    ];
@endphp

<div class="mx-auto max-w-5xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Reference data</h1>
        <p class="mt-1 text-sm text-slate-500">
            The lists the wizard selects from. A value that is not here cannot be chosen,
            which is what keeps routing and reporting reliable.
        </p>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    @error('toggle')
        <p class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- ---- Add ---------------------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Add an option</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-4">
            <div>
                <label for="kind" class="block text-xs font-medium text-slate-600">Type</label>
                <select id="kind" wire:model.live="kind"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="name" class="block text-xs font-medium text-slate-600">Name</label>
                <input id="name" type="text" wire:model="name" placeholder="e.g. Tier 3"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('name')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="code" class="block text-xs font-medium text-slate-600">Code</label>
                {{--
                    The code is what the database stores, so it is constrained.
                    A code with a space or a capital only matches if every future
                    comparison spells it identically — which is how the free-text drift
                    this screen prevents comes back.
                --}}
                <input id="code" type="text" wire:model="code" placeholder="tier_3"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <p class="mt-0.5 text-xs text-slate-500">Lowercase, numbers, underscores.</p>
                @error('code')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="add" wire:loading.attr="disabled"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="add">Add</span>
                    <span wire:loading wire:target="add">Adding…</span>
                </button>
            </div>
        </div>

        @if ($kind === 'route')
            {{--
                The routing rule, editable.
                Stored as data rather than derived from the route's name, so
                "does this reach the committee?" can change without a release.
            --}}
            <label class="mt-3 flex cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" wire:model="requires_committee"
                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                <span class="text-slate-700">This route requires an IT Investment Committee decision</span>
            </label>
        @endif
    </div>

    {{-- ---- Existing options --------------------------------------------- --}}
    @foreach ($sections as $section)
        <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
                {{ $section['title'] }}
            </h2>

            @if ($section['rows']->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-slate-500">
                    Nothing here yet. The wizard will have no options to offer.
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($section['rows'] as $row)
                        @php $key = $section['kind'].':'.$row->id; @endphp
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <label class="sr-only" for="name-{{ $key }}">Name</label>
                                <input id="name-{{ $key }}" type="text"
                                       wire:model="edits.{{ $key }}.name"
                                       class="w-full max-w-sm rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                @error("edits.{$key}.name")
                                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                @enderror

                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $row->code }}</p>
                            </div>

                            @if ($section['kind'] === 'route')
                                <label class="flex cursor-pointer items-center gap-2 text-xs">
                                    <input type="checkbox" wire:model="edits.{{ $key }}.requires_committee"
                                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="text-slate-600">Requires committee</span>
                                </label>
                            @endif

                            @if ($section['kind'] === 'tier')
                                {{--
                                    The band, and who decides the tier.

                                    Two nullable bounds rather than a threshold and a direction:
                                    "RM50,000 and below" and "RM50,001 and above" read as one
                                    rule, but a single threshold cannot express Tier P, which is
                                    neither. Empty means UNBOUNDED, not zero — "RM50,001 and above"
                                    has no ceiling, and recording one as 0 would describe a band
                                    that covers nothing.

                                    The bounds are inclusive at both ends, so neighbouring bands
                                    must not touch: RM50,000 and RM50,001, never RM50,000 and
                                    RM50,000. Saving an overlap is refused, because an amount
                                    matching both would have two answers.
                                --}}
                                <div class="w-full border-t border-slate-100 pt-3">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <label for="min-{{ $key }}" class="block text-xs font-medium text-slate-600">
                                                From (RM)
                                            </label>
                                            <input id="min-{{ $key }}" type="number" step="0.01" min="0" placeholder="no lower bound"
                                                   wire:model="edits.{{ $key }}.budget_min"
                                                   class="mt-1 w-36 rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                            @error("edits.{$key}.budget_min")
                                                <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="max-{{ $key }}" class="block text-xs font-medium text-slate-600">
                                                Up to (RM)
                                            </label>
                                            <input id="max-{{ $key }}" type="number" step="0.01" min="0" placeholder="no upper bound"
                                                   wire:model="edits.{{ $key }}.budget_max"
                                                   class="mt-1 w-36 rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                            @error("edits.{$key}.budget_max")
                                                <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="by-{{ $key }}" class="block text-xs font-medium text-slate-600">
                                                Chosen by
                                            </label>
                                            <select id="by-{{ $key }}" wire:model="edits.{{ $key }}.assignable_by"
                                                    class="mt-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                                <option value="requestor">Requestor — from the amount</option>
                                                <option value="governance">IT Governance</option>
                                            </select>
                                        </div>
                                    </div>

                                    <p class="mt-2 text-xs text-slate-500">
                                        Both bounds are <strong>inclusive</strong>, so adjacent tiers must not touch:
                                        RM50,000 and RM50,001, never RM50,000 and RM50,000.
                                        Leave a bound empty for "no limit". A tier with <strong>no bounds at all</strong>
                                        is not decided by budget — which is how Tier P works, and why it is chosen by
                                        governance rather than offered to the requestor.
                                    </p>
                                </div>
                            @endif

                            <label class="flex cursor-pointer items-center gap-2 text-xs">
                                <input type="checkbox" wire:model="edits.{{ $key }}.is_active"
                                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-slate-600">Active</span>
                            </label>

                            <div class="flex gap-2">
                                <button type="button" wire:click="save('{{ $section['kind'] }}', {{ $row->id }})"
                                        class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                    Save
                                </button>

                                {{--
                                    Deactivate, never delete.

                                    Every one of these tables is referenced by requests
                                    that already exist. Removing a row would either
                                    orphan them or cascade through them.
                                --}}
                                <button type="button"
                                        wire:click="toggleActive('{{ $section['kind'] }}', {{ $row->id }})"
                                        class="rounded-lg border px-3 py-1 text-xs font-medium {{ $row->is_active
                                            ? 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                            : 'border-green-300 text-green-800 hover:bg-green-50' }}">
                                    {{ $row->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    <p class="mt-4 text-xs text-slate-500">
        Options are deactivated rather than deleted, because requests already raised
        reference them. A deactivated option disappears from the pickers and every
        existing request keeps its value.
    </p>
</div>
