@php
    $stages = $stages ?? collect();
@endphp

<div class="mx-auto max-w-5xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Due dates and calendar</h1>
        <p class="mt-1 text-sm text-slate-500">
            Targets in working days, and the public holidays they skip.
        </p>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{-- ---- The working day -------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">The working day</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ rtrim(rtrim(number_format($hoursPerDay, 2), '0'), '.') }} working hours a day, with the
            lunch break excluded. Every target below is counted in whole working days, so a request
            raised on Friday and read on Monday is one day old rather than three.
        </p>
    </div>

    {{-- ---- Targets ------------------------------------------------------ --}}
    <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Stage targets</h2>
            {{--
                The non-retroactivity is stated on the screen, not only in a comment.

                An administrator who expects a changed target to move requests already
                waiting will otherwise conclude the save did not work — and will change
                it again.
            --}}
            <p class="mt-0.5 text-xs text-slate-500">
                A target applies when a stage is entered. Changing it here affects tasks
                created from now on; requests already waiting keep the target they were
                given. Leave a field blank for no target — the aging report will say so
                rather than invent a deadline.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Stage</th>
                        <th scope="col" class="px-4 py-2 font-medium">Default</th>
                        @foreach ($tiers as $tier)
                            <th scope="col" class="px-4 py-2 font-medium">{{ $tier->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($stages as $stage)
                        <tr>
                            <td class="px-4 py-2 text-slate-700">{{ $stage->name }}</td>

                            <td class="px-4 py-2">
                                <label class="sr-only" for="target-{{ $stage->id }}-0">
                                    {{ $stage->name }} default target in working days
                                </label>
                                <input id="target-{{ $stage->id }}-0" type="number" min="0" max="365"
                                       wire:model="targets.{{ $stage->id }}:0"
                                       class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                @error("targets.{$stage->id}:0")
                                    <p class="mt-1 text-xs text-red-700" role="alert">{{ $message }}</p>
                                @enderror
                            </td>

                            @foreach ($tiers as $tier)
                                <td class="px-4 py-2">
                                    {{-- A per-tier override. Blank means the default applies —
                                         `businessDaysFor()` prefers the override and falls
                                         back, so leaving it empty is not "no target". --}}
                                    <label class="sr-only" for="target-{{ $stage->id }}-{{ $tier->id }}">
                                        {{ $stage->name }} target for {{ $tier->name }}
                                    </label>
                                    <input id="target-{{ $stage->id }}-{{ $tier->id }}"
                                           type="number" min="0" max="365"
                                           wire:model="targets.{{ $stage->id }}:{{ $tier->id }}"
                                           placeholder="—"
                                           class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    @error("targets.{$stage->id}:{$tier->id}")
                                        <p class="mt-1 text-xs text-red-700" role="alert">{{ $message }}</p>
                                    @enderror
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            <button type="button" wire:click="saveTargets" wire:loading.attr="disabled"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="saveTargets">Save targets</span>
                <span wire:loading wire:target="saveTargets">Saving…</span>
            </button>
        </div>
    </div>

    {{-- ---- Holidays ----------------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Public holidays</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                A target in working days means nothing without these — a holiday nobody
                entered pushes every due date around it out by a day.
            </p>
        </div>

        <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-3">
            <div>
                <label for="newHolidayDate" class="block text-xs font-medium text-slate-600">Date</label>
                <input id="newHolidayDate" type="date" wire:model="newHolidayDate"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('newHolidayDate')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="newHolidayName" class="block text-xs font-medium text-slate-600">Name</label>
                <input id="newHolidayName" type="text" wire:model="newHolidayName"
                       placeholder="e.g. National Day"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('newHolidayName')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="addHoliday" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="addHoliday">Add holiday</span>
                    <span wire:loading wire:target="addHoliday">Adding…</span>
                </button>
            </div>
        </div>

        @if ($holidays->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500">
                No holidays are recorded. Every weekday currently counts as a working day.
            </p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($holidays as $holiday)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <div>
                            <span class="text-sm text-slate-900">{{ $holiday->name }}</span>
                            <span class="ml-2 text-xs text-slate-500">
                                {{ $holiday->date?->format('d M Y') }}
                            </span>

                            @if ($holiday->isManuallyOverridden())
                                {{-- Shown, because it explains why a later calendar
                                     sync will not touch or remove this row. --}}
                                <span class="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-900 ring-1 ring-amber-200">
                                    Entered by hand
                                </span>
                            @endif
                        </div>

                        <button type="button" wire:click="removeHoliday({{ $holiday->id }})"
                                wire:confirm="Remove this holiday? Due dates will no longer skip it."
                                class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Remove
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
