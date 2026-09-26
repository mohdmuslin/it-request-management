@php
    use App\Enums\BusinessPlanStatus;
    use App\Models\TierFieldRule;
    use App\Services\TierFieldRules;

    $steps = [
        1 => ['label' => 'Request information', 'summary' => 'What is being requested, and who owns it'],
        2 => ['label' => 'Business justification', 'summary' => 'Why it is needed, and how it aligns'],
        3 => ['label' => 'Budget and timeline', 'summary' => 'What it costs and when it lands'],
        4 => ['label' => 'Risk and scope', 'summary' => 'What could go wrong, and what is included'],
    ];

    // The business-plan branch is decided here rather than in four separate
    // comparisons, so the two fields cannot both be shown at once.
    $aligned = $business_plan_status === BusinessPlanStatus::Aligned->value;
    $adHoc = $business_plan_status === BusinessPlanStatus::AdHoc->value;

    /*
     * THE TIER'S RULES, RESOLVED ONCE FOR THIS RENDER (BR-003).
     *
     * Resolved in the view rather than pushed in as props, because three things need it here —
     * which fields to show, which to mark required, and the note explaining why a field is
     * missing — and threading one array through a component that already takes twelve would
     * make the dependency harder to see, not easier.
     *
     * It reaches the same service the form request and the component use, so the three cannot
     * disagree about what the rules are.
     */
    $tierRules = app(TierFieldRules::class)->forTier($proposed_tier_id);
    $ruleFor = fn (string $field): string => $tierRules[$field] ?? TierFieldRule::OPTIONAL;
    $isHidden = fn (string $field): bool => $ruleFor($field) === TierFieldRule::HIDDEN;
    $isRequired = fn (string $field): bool => $ruleFor($field) === TierFieldRule::REQUIRED;
@endphp

<div class="mx-auto max-w-4xl">

    {{-- ---- Header ---------------------------------------------------- --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">
                {{ $requestId ? 'Edit IT request' : 'New IT request' }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Four short steps. You can save a draft at any point and finish later.
            </p>
        </div>

        @if ($requestId)
            <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-medium text-slate-600">
                Draft saved
            </span>
        @endif
    </div>

    {{-- ---- Progress -------------------------------------------------- --}}
    {{--
        A progress bar, not just a number: it shows how much is left, and each
        step is a target that can be clicked. Forward movement runs the
        intervening checks, so the bar is not a way to skip validation.
    --}}
    <nav aria-label="Request steps" class="mt-5">
        <ol class="flex flex-wrap gap-2">
            @foreach ($steps as $number => $meta)
                @php
                    $isCurrent = $number === $step;
                    $isDone = $number < $step;
                @endphp

                <li class="flex-1">
                    <button type="button" wire:click="goTo({{ $number }})"
                            @if ($isCurrent) aria-current="step" @endif
                            @class([
                                'w-full rounded-lg border px-3 py-2 text-left transition',
                                'border-indigo-500 bg-indigo-50' => $isCurrent,
                                'border-slate-200 bg-white hover:border-slate-300' => ! $isCurrent,
                            ])>
                        <span @class([
                            'block text-[11px] font-semibold uppercase tracking-wide',
                            'text-indigo-600' => $isCurrent || $isDone,
                            'text-slate-400' => ! $isCurrent && ! $isDone,
                        ])>
                            Step {{ $number }}@if ($isDone) ✓@endif
                        </span>
                        <span @class([
                            'mt-0.5 block text-xs font-medium',
                            'text-slate-900' => $isCurrent,
                            'text-slate-600' => ! $isCurrent,
                        ])>{{ $meta['label'] }}</span>
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{-- ---- The form -------------------------------------------------- --}}
    <form wire:submit.prevent="submit" class="mt-5">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">

            <div class="mb-5 border-b border-slate-100 pb-3">
                <h2 class="text-base font-semibold text-slate-900">
                    Step {{ $step }} — {{ $steps[$step]['label'] }}
                </h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ $steps[$step]['summary'] }}</p>
            </div>

            @if ($step === 1)
                {{-- =============== STEP 1 ============================== --}}
                <div class="grid gap-5 sm:grid-cols-2">

                    <div class="sm:col-span-2">
                        <x-ui.field name="title" label="Request title" required
                                    hint="A short name an approver can recognise in a list.">
                            <x-ui.control model="title" name="title" type="text" required autofocus />
                        </x-ui.field>
                    </div>

                    <x-ui.field name="request_date" label="Request date" required>
                        <x-ui.control model="request_date" name="request_date" type="date" required />
                    </x-ui.field>

                    {{-- The requestor is the signed-in user, shown but not editable. --}}
                    <x-ui.field name="requestor" label="Requestor"
                                hint="Requests are always raised in your own name.">
                        <input type="text" value="{{ auth()->user()->name }}" disabled
                               class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                    </x-ui.field>

                    <x-ui.field name="department_id" label="Department" required
                                hint="Changing this clears the division.">
                        <x-ui.control model="department_id" name="department_id" :options="$departments->pluck('name', 'id')" placeholder="Select a department…" />
                    </x-ui.field>

                    <x-ui.field name="division_id" label="Division">
                        @if ($department_id)
                            <x-ui.control model="division_id" name="division_id" :options="$divisions->pluck('name', 'id')" placeholder="Select a division…" />
                        @else
                            <select disabled class="block w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-400">
                                <option>Choose a department first</option>
                            </select>
                        @endif
                    </x-ui.field>

                    <x-ui.field name="project_owner_id" label="Project owner" required
                                hint="Approves first, and is accountable for delivery.">
                        <x-ui.control model="project_owner_id" name="project_owner_id" :options="$people->pluck('name', 'id')" placeholder="Select the owner…" />
                    </x-ui.field>

                    <x-ui.field name="project_sponsor_id" label="Project sponsor"
                                hint="Approves after the owner. Must be someone else.">
                        <x-ui.control model="project_sponsor_id" name="project_sponsor_id" :options="$people->pluck('name', 'id')" placeholder="Select the sponsor…" />
                    </x-ui.field>

                    <x-ui.field name="proposed_tier_id" label="Proposed tier"
                                hint="Your suggestion. IT Governance confirms it.">
                        <x-ui.control model="proposed_tier_id" name="proposed_tier_id" :options="$tiers->pluck('name', 'id')" />
                    </x-ui.field>

                    <x-ui.field name="proposed_classification_id" label="Proposed classification"
                                hint="Your suggestion. IT Governance confirms it.">
                        <x-ui.control model="proposed_classification_id" name="proposed_classification_id" :options="$classifications->pluck('name', 'id')" />
                    </x-ui.field>
                </div>

            @elseif ($step === 2)
                {{-- =============== STEP 2 ============================== --}}
                <div class="space-y-5">

                    <x-ui.field name="business_need" label="What is the business need?" required
                                hint="The problem, not the product. At least a couple of sentences.">
                        <x-ui.control model="business_need" name="business_need" type="textarea" rows="4" />
                    </x-ui.field>

                    <x-ui.field name="business_plan_status" label="Is this in the business plan?" required>
                        <x-ui.control model="business_plan_status" name="business_plan_status" :options="$planStatuses" />
                    </x-ui.field>

                    {{--
                        BR-003. Exactly one of the two fields below applies, decided
                        by the answer above. Showing both would invite a requestor to
                        fill in whichever they preferred, and the record would then
                        contain a justification for a claim they did not make.
                    --}}
                    @if ($aligned)
                        <x-ui.field name="business_plan_reference" label="Business plan reference" required
                                    hint="The plan, or the line in it, that covers this request.">
                            <x-ui.control model="business_plan_reference" name="business_plan_reference" type="text" />
                        </x-ui.field>
                    @elseif ($adHoc)
                        <x-ui.field name="adhoc_justification" label="Why is it not in the business plan?" required
                                    hint="An ad hoc request is allowed — but it has to make its case.">
                            <x-ui.control model="adhoc_justification" name="adhoc_justification" type="textarea" rows="3" />
                        </x-ui.field>
                    @else
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3">
                            <p class="text-xs text-slate-500">
                                Choose a business plan status above. An aligned request cites the plan;
                                an ad hoc request explains why it is still needed.
                            </p>
                        </div>
                    @endif

                    <x-ui.field name="value_proposition" label="What value does it deliver?"
                                hint="Time saved, revenue, risk removed, compliance met. Optional.">
                        <x-ui.control model="value_proposition" name="value_proposition" type="textarea" rows="3" />
                    </x-ui.field>
                </div>

            @elseif ($step === 3)
                {{-- =============== STEP 3 ============================== --}}
                @if (collect(['budget_amount', 'funding_type', 'budget_source', 'budget_code', 'proposed_start_date', 'target_completion_date', 'forecast_resources'])->contains($isHidden))
                    {{--
                        A field this tier does not use is NAMED here rather than silently absent.

                        An empty gap where a budget used to be reads as a bug, and the requestor
                        has no way to tell "not applicable to Tier 1" from "the form failed to
                        load". One line answers it.
                    --}}
                    <p class="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600 ring-1 ring-slate-200">
                        Not applicable to <strong>{{ $tiers->firstWhere('id', $proposed_tier_id)?->name ?? 'this tier' }}</strong>:
                        {{ collect(['budget_amount' => 'Budget amount', 'funding_type' => 'Funding type', 'budget_source' => 'Budget source', 'budget_code' => 'Budget code', 'proposed_start_date' => 'Proposed start date', 'target_completion_date' => 'Target completion', 'forecast_resources' => 'Resources required'])->filter(fn ($label, $field) => $isHidden($field))->join(', ') }}.
                    </p>
                @endif

                <div class="grid gap-5 sm:grid-cols-2">

                    @unless ($isHidden('budget_amount'))
                        <x-ui.field name="budget_amount" label="Budget amount (RM)"
                                    :required="$isRequired('budget_amount')"
                                    :hint="$isRequired('budget_amount')
                                        ? 'Required for this tier.'
                                        : 'Figures only. Leave blank if not yet costed.'">
                            <x-ui.control model="budget_amount" name="budget_amount" type="number" step="0.01" min="0" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('funding_type'))
                        <x-ui.field name="funding_type" label="Funding type" :required="$isRequired('funding_type')">
                            <x-ui.control model="funding_type" name="funding_type" type="text"
                                          placeholder="e.g. CapEx, OpEx" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('budget_source'))
                        <x-ui.field name="budget_source" label="Budget source" :required="$isRequired('budget_source')">
                            <x-ui.control model="budget_source" name="budget_source" type="text" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('budget_code'))
                        <x-ui.field name="budget_code" label="Budget code" :required="$isRequired('budget_code')">
                            <x-ui.control model="budget_code" name="budget_code" type="text" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('proposed_start_date'))
                        <x-ui.field name="proposed_start_date" label="Proposed start date" :required="$isRequired('proposed_start_date')">
                            <x-ui.control model="proposed_start_date" name="proposed_start_date" type="date" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('target_completion_date'))
                        <x-ui.field name="target_completion_date" label="Target completion"
                                    :required="$isRequired('target_completion_date')"
                                    hint="Cannot be earlier than the start date.">
                            <x-ui.control model="target_completion_date" name="target_completion_date" type="date" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('forecast_resources'))
                        <div class="sm:col-span-2">
                            <x-ui.field name="forecast_resources" label="Resources required"
                                        :required="$isRequired('forecast_resources')"
                                        hint="People, licences, hardware, vendor effort.">
                                <x-ui.control model="forecast_resources" name="forecast_resources" type="textarea" rows="3" />
                            </x-ui.field>
                        </div>
                    @endunless
                </div>

            @else
                {{-- =============== STEP 4 ============================== --}}
                <div class="space-y-5">

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field name="urgency" label="Urgency" required>
                            <x-ui.control model="urgency" name="urgency"
                                          :options="['low' => 'Low', 'medium' => 'Medium', 'high' => 'High']" />
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            {{--
                                Shown only for High. Requiring a reason every time
                                trains people to type "urgent", which is worse than
                                leaving it blank; requiring it here means the field
                                carries information exactly when it matters.
                            --}}
                            @if ($urgency === 'high')
                                <x-ui.field name="urgency_justification" label="Reason for the urgency" required
                                            hint="A date or an obligation an approver can evaluate — not a restatement.">
                                    <x-ui.control model="urgency_justification" name="urgency_justification" type="textarea" rows="2" />
                                </x-ui.field>
                            @endif
                        </div>
                    </div>

                    @if (collect(['risk_summary', 'mitigation_plan', 'dependencies_constraints', 'in_scope', 'out_of_scope'])->contains($isHidden))
                        {{-- Named for the same reason as step 3: a gap where a field used to be
                             reads as a broken screen unless something says why it is gone. --}}
                        <p class="rounded-lg bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600 ring-1 ring-slate-200">
                            Not applicable to <strong>{{ $tiers->firstWhere('id', $proposed_tier_id)?->name ?? 'this tier' }}</strong>:
                            {{ collect(['risk_summary' => 'Risks', 'mitigation_plan' => 'Mitigation', 'dependencies_constraints' => 'Dependencies and constraints', 'in_scope' => 'In scope', 'out_of_scope' => 'Out of scope'])->filter(fn ($label, $field) => $isHidden($field))->join(', ') }}.
                        </p>
                    @endif

                    <x-ui.field name="impact_if_not_implemented" label="What happens if this is not implemented?" required
                                hint="The field approvers rely on most. Be specific.">
                        <x-ui.control model="impact_if_not_implemented" name="impact_if_not_implemented" type="textarea" rows="3" />
                    </x-ui.field>

                    @unless ($isHidden('risk_summary'))
                        <x-ui.field name="risk_summary" label="Risks" :required="$isRequired('risk_summary')">
                            <x-ui.control model="risk_summary" name="risk_summary" type="textarea" rows="3" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('mitigation_plan'))
                        <x-ui.field name="mitigation_plan" label="Mitigation" :required="$isRequired('mitigation_plan')">
                            <x-ui.control model="mitigation_plan" name="mitigation_plan" type="textarea" rows="3" />
                        </x-ui.field>
                    @endunless

                    @unless ($isHidden('dependencies_constraints'))
                        <x-ui.field name="dependencies_constraints" label="Dependencies and constraints"
                                    :required="$isRequired('dependencies_constraints')">
                            <x-ui.control model="dependencies_constraints" name="dependencies_constraints" type="textarea" rows="3" />
                        </x-ui.field>
                    @endunless

                    <div class="grid gap-5 sm:grid-cols-2">
                        @unless ($isHidden('in_scope'))
                            <x-ui.field name="in_scope" label="In scope" :required="$isRequired('in_scope')">
                                <x-ui.control model="in_scope" name="in_scope" type="textarea" rows="3" />
                            </x-ui.field>
                        @endunless

                        @unless ($isHidden('out_of_scope'))
                            <x-ui.field name="out_of_scope" label="Out of scope"
                                        :required="$isRequired('out_of_scope')"
                                        hint="Naming what is excluded prevents the commonest dispute later.">
                                <x-ui.control model="out_of_scope" name="out_of_scope" type="textarea" rows="3" />
                            </x-ui.field>
                        @endunless
                    </div>

                    {{-- What the requestor is about to hand over. --}}
                    <div class="rounded-lg bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <p class="text-xs font-medium text-slate-700">On submission</p>
                        <p class="mt-1 text-xs text-slate-500">
                            The request goes to
                            <span class="font-medium text-slate-700">
                                {{ $people->firstWhere('id', (int) $project_owner_id)?->name ?? 'the project owner' }}
                            </span>
                            for approval, then to the sponsor. You can edit it again only if it is
                            returned to you for amendment.
                        </p>
                    </div>
                </div>
            @endif
        </div>

        {{-- ---- Actions ------------------------------------------------- --}}
        {{--
            The draft button is available at every step and is a plain button, not
            a submit — pressing Enter in a text field must not silently save a
            draft when the user meant to continue.
        --}}
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-2">
                @if ($step > 1)
                    <button type="button" wire:click="previous"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Back
                    </button>
                @endif

                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                    Save draft
                </button>
            </div>

            <div class="flex gap-2">
                @if ($step < $lastStep)
                    <button type="button" wire:click="next"
                            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Continue
                    </button>
                @else
                    <button type="submit" wire:loading.attr="disabled"
                            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="submit">Submit for approval</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>
                @endif
            </div>
        </div>
    </form>
</div>
