{{--
    Tier field rules (BR-003).

    A grid rather than a form per tier, so the comparison is visible. See the component for why.
--}}
<div class="mx-auto max-w-6xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Tier field rules</h1>
        <p class="mt-1 text-sm text-slate-500">
            Which fields a tier requires, ignores, or does not apply to.
        </p>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{--
        The three states, explained once at the top.

        "Hidden" is the one that needs saying: it is not "optional". A field that is optional
        accepts a blank; one that is hidden does not apply, and a value left in it from before a
        tier change would be stored against a tier where it means nothing.
    --}}
    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-xs leading-relaxed text-slate-600 ring-1 ring-slate-200">
        <p><strong class="text-slate-800">Required</strong> — the request cannot be submitted without it.</p>
        <p class="mt-1"><strong class="text-slate-800">Optional</strong> — accepted, and may be left blank. This is the default: a field with no rule is optional.</p>
        <p class="mt-1"><strong class="text-slate-800">Hidden</strong> — does not apply to this tier. The field is not shown, and any value already in it is <em>cleared</em> when the tier is chosen.</p>
        <p class="mt-2 text-slate-500">
            <strong class="text-slate-700">Every tier</strong> is the fallback: it applies to any tier without its own rule.
            A tier's own setting always wins.
        </p>
    </div>

    {{--
        The fields that are NOT governed, named rather than omitted.

        An administrator looking for "budget amount" will find it below; one looking for "impact
        if not implemented" will not, and without this note would reasonably conclude the screen
        was incomplete rather than that the field is always required.
    --}}
    <p class="mt-4 text-xs leading-relaxed text-slate-500">
        Not listed, because they are always required and a rule could break the workflow:
        title, department, project owner, business need, impact if not implemented, and urgency.
        The business-plan reference and ad-hoc justification are governed by whether the request
        claims plan alignment, not by tier — so a tier cannot make either optional.
    </p>

    <form wire:submit="save" class="mt-5">
        @error('edits.*')
            <p class="mb-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">{{ $message }}</p>
        @enderror

        @foreach ($groups as $group)
            <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $group['label'] }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Wizard step {{ $group['step'] }}</p>
                </div>

                {{--
                    Horizontal scroll on a narrow screen, rather than stacking.

                    Stacking a grid turns a comparison into a list, and the comparison is the
                    whole point of the screen. A scrollable table keeps the columns aligned, which
                    is what makes "budget is required in two of three tiers" visible at a glance.
                --}}
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <caption class="sr-only">Field requirements by tier</caption>
                        <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">Field</th>
                                @foreach ($tiers as $key => $tier)
                                    <th scope="col" class="px-4 py-2 font-medium {{ $key === 'default' ? 'bg-slate-50' : '' }}">
                                        {{ $tier?->name ?? 'Every tier' }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($group['fields'] as $field => $label)
                                <tr>
                                    <th scope="row" class="px-4 py-2 font-normal text-slate-700">
                                        {{ $label }}
                                        <span class="block font-mono text-[10px] text-slate-400">{{ $field }}</span>
                                    </th>

                                    @foreach ($tiers as $key => $tier)
                                        <td class="px-4 py-2 {{ $key === 'default' ? 'bg-slate-50' : '' }}">
                                            <label class="sr-only" for="rule-{{ $key }}-{{ $field }}">
                                                {{ $label }} for {{ $tier?->name ?? 'every tier' }}
                                            </label>
                                            <select id="rule-{{ $key }}-{{ $field }}"
                                                    wire:model="edits.{{ $key }}:{{ $field }}"
                                                    class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                                @foreach ($requirements as $value => $requirementLabel)
                                                    <option value="{{ $value }}">{{ $requirementLabel }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Save the rules</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

            <p class="text-xs text-slate-500">
                Setting a field back to <strong>Optional</strong> removes its rule.
            </p>
        </div>
    </form>
</div>
