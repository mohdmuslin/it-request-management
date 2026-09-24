@php
    use App\Enums\RequestStatus;

    $r = $request;
@endphp

<div class="mx-auto max-w-4xl">

    {{-- ---- Back + header --------------------------------------------- --}}
    <a href="{{ route('requests.index') }}" wire:navigate
       class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-700">
        <span aria-hidden="true">←</span> My requests
    </a>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-slate-900">{{ $r->title }}</h1>
            <p class="mt-1 font-mono text-sm text-slate-500">{{ $r->request_no }}</p>
        </div>

        <x-ui.status :status="$r->statusEnum()" />
    </div>

    {{-- ---- What happens next ------------------------------------------ --}}
    {{--
        The single most useful thing on the page for a requestor: not the status,
        but whose hands it is in and by when. A status label alone ("Pending Project
        Sponsor") makes the reader work out who that is.
    --}}
    @if ($pendingTask)
        @php $overdue = $pendingTask->due_at?->isPast(); @endphp
        <div class="{{ $overdue ? 'bg-red-50 ring-red-200' : 'bg-blue-50 ring-blue-200' }} mt-4 rounded-xl px-4 py-3 ring-1">
            <p class="text-sm {{ $overdue ? 'text-red-900' : 'text-blue-900' }}">
                <span class="font-medium">Waiting on</span>
                {{ $pendingTask->approver?->name ?? 'an unassigned approver' }}
                <span class="text-xs">({{ \App\Enums\WorkflowStage::from($pendingTask->stage)->label() }})</span>
            </p>

            @if ($pendingTask->due_at)
                <p class="mt-0.5 text-xs {{ $overdue ? 'font-medium text-red-800' : 'text-blue-800' }}">
                    @if ($overdue) ⚠ Overdue since @else Target @endif
                    {{ $pendingTask->due_at->format('d M Y') }}
                </p>
            @endif

            @if ($pendingTask->approver === null)
                {{-- An unassigned task is a real problem, and saying so lets an
                     administrator fix it instead of the request stalling silently. --}}
                <p class="mt-0.5 text-xs text-blue-800">
                    Nobody holds this role yet. An administrator needs to assign one.
                </p>
            @endif
        </div>
    @endif

    {{-- ---- Actions ---------------------------------------------------- --}}
    @if ($canSubmit || $canEdit || $canWithdraw || $canResubmit || $canDecide)
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canEdit)
                {{--
                    Points at the EDIT route, not the create wizard.

                    The first version linked to `requests.create`, so "Edit draft"
                    opened a blank form — the request id was never passed, and the
                    only way to find that out was to click it. Nothing in the test
                    suite caught it because the tests drive the component directly
                    rather than following the link.
                --}}
                <a href="{{ route('requests.edit', $r) }}" wire:navigate
                   class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    {{ $r->statusEnum() === RequestStatus::ReturnedForAmendment ? 'Amend and resubmit' : 'Edit draft' }}
                </a>
            @endif

            @if ($canSubmit)
                <button type="button" wire:click="submit" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="submit">Submit for approval</span>
                    <span wire:loading wire:target="submit">Submitting…</span>
                </button>
            @endif

            {{--
                Resubmit, for a returned request.

                BR-007 says a returned request resumes at the stage that returned it.
                That is asserted by a service test, but until this button existed the
                behaviour could not be reached from the application at all — the screen
                offered only "Edit draft" and "Withdraw", so a requestor had no way to
                send their amendment back.
            --}}
            @if ($canResubmit)
                <button type="button" wire:click="resubmit" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="resubmit">Resubmit</span>
                    <span wire:loading wire:target="resubmit">Resubmitting…</span>
                </button>
            @endif

            @if ($canDecide)
                <a href="{{ route('approvals.index') }}" wire:navigate
                   class="rounded-lg bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 ring-1 ring-amber-200">
                    This is awaiting your decision
                </a>
            @endif

            @if ($canWithdraw && $r->statusEnum() !== RequestStatus::Draft)
                <button type="button" wire:click="withdraw"
                        wire:confirm="Withdraw this request? It stops here and cannot be resubmitted."
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Withdraw
                </button>
            @endif
        </div>
    @endif

    {{-- ==== Governance actions ========================================== --}}
    {{--
        One button per capability, and each is gated on the POLICY — the same call
        the action itself makes. A button that appears and then refuses is worse than
        no button, and that mismatch has already happened once in this project (the
        navigation offered "New Request" while the menu hid it).
    --}}
    @php
        $hasGovernanceAction = $canAssess || $canRecommend || $canConsolidate || $canCommittee || $canClose;
    @endphp

    @if ($hasGovernanceAction)
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-sm font-semibold text-slate-900">Your governance actions</h2>

            <div class="mt-3 flex flex-wrap gap-2">
                @if ($canAssess)
                    <button type="button" wire:click="openPanel('assess')"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Assess completeness
                    </button>
                @endif

                @if ($canRecommend)
                    <button type="button" wire:click="openPanel('recommend')"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        File a recommendation
                    </button>
                @endif

                @if ($canConsolidate)
                    <button type="button" wire:click="openPanel('consolidate')"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Consolidate and set the route
                    </button>
                @endif

                @if ($canCommittee)
                    <button type="button" wire:click="openPanel('committee')"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Record the committee decision
                    </button>
                @endif

                @if ($canClose)
                    <button type="button" wire:click="openPanel('close')"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Close the request
                    </button>
                @endif
            </div>
        </div>
    @endif

    @error('close')
        <p class="mt-4 whitespace-pre-line rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- ---- Assess --------------------------------------------------------- --}}
    @if ($panel === 'assess')
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">Completeness review</h2>
            <p class="mt-1 text-sm text-slate-500">
                Confirm or correct the tier and classification. The requestor's proposal is kept,
                so the trail shows what changed and why.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="assess_tier_id" class="block text-sm font-medium text-slate-700">
                        Tier <span class="text-red-600">*</span>
                    </label>
                    <select id="assess_tier_id" wire:model="assess_tier_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Select a tier…</option>
                        @foreach ($tiers as $tier)
                            <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                        @endforeach
                    </select>
                    @if ($r->proposedTier)
                        <p class="mt-0.5 text-xs text-slate-500">Requestor proposed: {{ $r->proposedTier->name }}</p>
                    @endif
                    @error('assess_tier_id')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="assess_classification_id" class="block text-sm font-medium text-slate-700">
                        Classification <span class="text-red-600">*</span>
                    </label>
                    <select id="assess_classification_id" wire:model="assess_classification_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Select a classification…</option>
                        @foreach ($classifications as $classification)
                            <option value="{{ $classification->id }}">{{ $classification->name }}</option>
                        @endforeach
                    </select>
                    @if ($r->proposedClassification)
                        <p class="mt-0.5 text-xs text-slate-500">
                            Requestor proposed: {{ $r->proposedClassification->name }}
                        </p>
                    @endif
                    @error('assess_classification_id')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="assess_reason" class="block text-sm font-medium text-slate-700">
                        Reason for any change
                    </label>
                    {{--
                        Required only when the values actually change, which the service
                        decides. Asking every time would train people to type ".".
                    --}}
                    <p class="mt-0.5 text-xs text-slate-500">
                        Required if you change the tier or classification. A silent
                        reclassification is what the requestor will dispute.
                    </p>
                    <textarea id="assess_reason" wire:model="assess_reason" rows="2"
                              class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                    @error('assess_reason')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="assess_notes" class="block text-sm font-medium text-slate-700">Review notes</label>
                    <textarea id="assess_notes" wire:model="assess_notes" rows="2"
                              class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="assess" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="assess">Record assessment</span>
                    <span wire:loading wire:target="assess">Recording…</span>
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ---- Recommend ------------------------------------------------------ --}}
    @if ($panel === 'recommend')
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">File a recommendation</h2>
            <p class="mt-1 text-sm text-slate-500">
                Each reviewing unit files independently. A revision is kept as a new version —
                nothing is overwritten, so the committee sees how a unit's position changed.
            </p>

            @if ($myUnits->isEmpty())
                {{-- Said plainly rather than showing a form that cannot submit. --}}
                <p class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    You do not belong to any of the units assigned to review this request
                    ({{ $assignedUnits->pluck('name')->join(', ') ?: 'none assigned' }}), so there is
                    nothing for you to file.
                </p>
            @else
                <div class="mt-4 space-y-4">
                    <div>
                        <label for="recommend_unit_id" class="block text-sm font-medium text-slate-700">
                            Reviewing unit <span class="text-red-600">*</span>
                        </label>
                        <select id="recommend_unit_id" wire:model="recommend_unit_id"
                                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Select your unit…</option>
                            @foreach ($myUnits as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endforeach
                        </select>
                        @error('recommend_unit_id')
                            <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="recommend_outcome" class="block text-sm font-medium text-slate-700">
                            Recommendation <span class="text-red-600">*</span>
                        </label>
                        <select id="recommend_outcome" wire:model.live="recommend_outcome"
                                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Select a position…</option>
                            @foreach ($recommendationOutcomes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('recommend_outcome')
                            <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($recommend_outcome === 'recommended_with_conditions')
                        <div>
                            <label for="recommend_conditions" class="block text-sm font-medium text-slate-700">
                                Conditions <span class="text-red-600">*</span>
                            </label>
                            <textarea id="recommend_conditions" wire:model="recommend_conditions" rows="2"
                                      class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                            @error('recommend_conditions')
                                <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <div>
                        <label for="recommend_evidence" class="block text-sm font-medium text-slate-700">
                            Reason / evidence
                            @if ($recommend_outcome === 'not_recommended')
                                <span class="text-red-600">*</span>
                            @endif
                        </label>
                        @if ($recommend_outcome === 'not_recommended')
                            <p class="mt-0.5 text-xs text-slate-500">
                                Advising against requires the reason. The IT HOU consolidates from
                                these, and the committee needs something to weigh against the case.
                            </p>
                        @endif
                        <textarea id="recommend_evidence" wire:model="recommend_evidence" rows="3"
                                  class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('recommend_evidence')
                            <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="recommend" wire:loading.attr="disabled"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="recommend">File recommendation</span>
                        <span wire:loading wire:target="recommend">Filing…</span>
                    </button>
                    <button type="button" wire:click="closePanel"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- ---- Consolidate ---------------------------------------------------- --}}
    @if ($panel === 'consolidate')
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">Consolidate and set the route</h2>
            <p class="mt-1 text-sm text-slate-500">
                The route is the outcome of this decision, not an input from the requestor.
                It determines whether the request reaches the IT Investment Committee.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="consolidate_route_id" class="block text-sm font-medium text-slate-700">
                        Governance route <span class="text-red-600">*</span>
                    </label>
                    <select id="consolidate_route_id" wire:model.live="consolidate_route_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Select a route…</option>
                        @foreach ($routes as $route)
                            <option value="{{ $route->id }}">
                                {{ $route->name }}{{ $route->requires_committee ? ' — requires committee' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('consolidate_route_id')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="consolidate_summary" class="block text-sm font-medium text-slate-700">
                        Consolidation summary <span class="text-red-600">*</span>
                    </label>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Why this route. Stored on the request and on the consolidation record, so a
                        later question about why a request did or did not reach the committee has an answer.
                    </p>
                    <textarea id="consolidate_summary" wire:model="consolidate_summary" rows="4"
                              class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                    @error('consolidate_summary')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="consolidate" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="consolidate">Record consolidation</span>
                    <span wire:loading wire:target="consolidate">Recording…</span>
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ---- Committee ------------------------------------------------------ --}}
    @if ($panel === 'committee')
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">Committee decision</h2>
            <p class="mt-1 text-sm text-slate-500">
                Recorded for the IT Investment Committee on the {{ $r->governanceRoute?->name }} route.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="committee_decision" class="block text-sm font-medium text-slate-700">
                        Decision <span class="text-red-600">*</span>
                    </label>
                    <select id="committee_decision" wire:model.live="committee_decision"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Select a decision…</option>
                        @foreach ($committeeDecisions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('committee_decision')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                @if ($committee_decision === 'approved_with_conditions')
                    <div>
                        <label for="committee_conditions" class="block text-sm font-medium text-slate-700">
                            Conditions <span class="text-red-600">*</span>
                        </label>
                        <textarea id="committee_conditions" wire:model="committee_conditions" rows="2"
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                        @error('committee_conditions')
                            <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div>
                    <label for="committee_comments" class="block text-sm font-medium text-slate-700">
                        Comment
                        @if (in_array($committee_decision, ['returned', 'rejected'], true))
                            <span class="text-red-600">*</span>
                        @endif
                    </label>
                    <textarea id="committee_comments" wire:model="committee_comments" rows="3"
                              class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                    @error('committee_comments')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="recordCommitteeDecision" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="recordCommitteeDecision">Record decision</span>
                    <span wire:loading wire:target="recordCommitteeDecision">Recording…</span>
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ---- Close ---------------------------------------------------------- --}}
    @if ($panel === 'close')
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-900">Close the request</h2>

            @if (count($closureBlockers) > 0)
                {{--
                    BR-006, shown as a checklist rather than a refusal.

                    "Cannot close" with no reason is a dead end; the list is the whole
                    value of the guard, because each line names something somebody can
                    go and do.
                --}}
                <p class="mt-2 text-sm font-medium text-slate-700">
                    Closure is blocked. {{ count($closureBlockers) }}
                    {{ Str::plural('item', count($closureBlockers)) }} outstanding:
                </p>
                <ul class="mt-2 space-y-1">
                    @foreach ($closureBlockers as $blocker)
                        <li class="flex gap-2 text-sm text-slate-600">
                            <span aria-hidden="true">•</span>
                            <span>{{ $blocker }}</span>
                        </li>
                    @endforeach
                </ul>

                <button type="button" wire:click="closePanel"
                        class="mt-4 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Close this panel
                </button>
            @else
                <p class="mt-1 text-sm text-slate-500">
                    All mandatory decisions and documentation are recorded. Closing records the
                    outcome and ends the request.
                </p>

                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="closeRequest" wire:loading.attr="disabled"
                            wire:confirm="Close this request? It cannot be reopened."
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="closeRequest">Close the request</span>
                        <span wire:loading wire:target="closeRequest">Closing…</span>
                    </button>
                    <button type="button" wire:click="closePanel"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- ---- Governance record ---------------------------------------------- --}}
    @if ($r->completenessAssessment || $r->recommendations->isNotEmpty() || $r->consolidation || $r->committeeDecision)
        <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
                Governance record
            </h2>

            <div class="divide-y divide-slate-100">
                @if ($r->completenessAssessment)
                    @php $assessment = $r->completenessAssessment; @endphp
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Completeness review
                        </h3>
                        <p class="mt-1 text-sm text-slate-700">
                            {{ $assessment->tier?->name }} ·
                            {{ $assessment->classification?->name }}
                            <span class="text-slate-500">
                                — {{ $assessment->assessedBy?->name }},
                                {{ $assessment->completed_at?->format('d M Y') }}
                            </span>
                        </p>

                        @if ($assessment->reclassification_reason)
                            <p class="mt-1 whitespace-pre-line rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                <span class="font-medium">Changed from the proposal:</span>
                                {{ $assessment->reclassification_reason }}
                            </p>
                        @endif
                    </div>
                @endif

                @if ($r->recommendations->isNotEmpty())
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Recommendations
                        </h3>

                        @php
                            // Grouped by unit so every version of one unit's position sits
                            // together — the point of versioning is to see the movement.
                            $byUnit = $r->recommendations->groupBy('review_unit_id');
                        @endphp

                        <ul class="mt-2 space-y-3">
                            @foreach ($byUnit as $versions)
                                @php
                                    $current = $versions->last();

                                    /*
                                     * The column holds the enum VALUE, so it must be mapped
                                     * to its label for display. Reading the raw attribute
                                     * printed "recommended_with_conditions" and
                                     * "not_recommended" on screen — accurate, and not
                                     * something to show a committee.
                                     */
                                    $outcome = \App\Enums\RecommendationOutcome::tryFrom($current->recommendation);
                                @endphp
                                <li>
                                    <p class="text-sm text-slate-900">
                                        <span class="font-medium">{{ $current->reviewUnit?->name }}</span>
                                        <span class="{{ $outcome?->isConcern() ? 'font-medium text-amber-800' : 'text-slate-500' }}">
                                            — {{ $outcome?->label() ?? $current->recommendation }}
                                        </span>
                                        <span class="text-xs text-slate-400">
                                            {{ $current->reviewer?->name }},
                                            {{ $current->submitted_at?->format('d M Y') }}
                                        </span>
                                    </p>

                                    @if ($current->conditions)
                                        <p class="mt-0.5 text-xs text-slate-600">
                                            Conditions: {{ $current->conditions }}
                                        </p>
                                    @endif

                                    @if ($current->evidence)
                                        <p class="mt-0.5 whitespace-pre-line text-xs text-slate-600">
                                            {{ $current->evidence }}
                                        </p>
                                    @endif

                                    {{-- Earlier versions, kept visible (BR-005). A unit that
                                         changed its position has told the committee something. --}}
                                    @if ($versions->count() > 1)
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-700">
                                                {{ $versions->count() - 1 }} earlier
                                                {{ Str::plural('version', $versions->count() - 1) }}
                                            </summary>
                                            <ul class="mt-1 space-y-1 border-l-2 border-slate-100 pl-3">
                                                @foreach ($versions->slice(0, -1) as $old)
                                                    <li class="text-xs text-slate-500">
                                                        v{{ $old->version_no }}:
                                                        {{ \App\Enums\RecommendationOutcome::tryFrom($old->recommendation)?->label() ?? $old->recommendation }}
                                                        — {{ $old->reviewer?->name }},
                                                        {{ $old->submitted_at?->format('d M Y') }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($r->consolidation)
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Consolidation
                        </h3>
                        <p class="mt-1 text-sm text-slate-900">
                            Route: <span class="font-medium">{{ $r->consolidation->governanceRoute?->name }}</span>
                            <span class="text-slate-500">
                                — {{ $r->consolidation->consolidatedBy?->name }},
                                {{ $r->consolidation->consolidated_at?->format('d M Y') }}
                            </span>
                        </p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">
                            {{ $r->consolidation->summary }}
                        </p>
                    </div>
                @endif

                @if ($r->committeeDecision)
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Committee decision
                        </h3>
                        <p class="mt-1 text-sm text-slate-900">
                            {{ $r->committeeDecision->decision?->label() }}
                            <span class="text-slate-500">
                                — {{ $r->committeeDecision->recordedBy?->name }},
                                {{ $r->committeeDecision->decided_at?->format('d M Y') }}
                            </span>
                        </p>
                        @if ($r->committeeDecision->conditions)
                            <p class="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                <span class="font-medium">Conditions:</span>
                                {{ $r->committeeDecision->conditions }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ---- The request itself ----------------------------------------- --}}
    <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <dl class="divide-y divide-slate-100 sm:grid sm:grid-cols-2 sm:divide-y-0">
            @php
                $rows = [
                    'Requestor' => $r->requestor?->name,
                    'Department' => $r->department?->name,
                    'Division' => $r->division?->name,
                    'Project owner' => $r->projectOwner?->name,
                    'Project sponsor' => $r->projectSponsor?->name,
                    'Request date' => $r->request_date?->format('d M Y'),
                    'Proposed tier' => $r->proposedTier?->name,
                    'Proposed classification' => $r->proposedClassification?->name,
                    'Confirmed tier' => $r->tier?->name,
                    'Confirmed classification' => $r->classification?->name,
                    'Governance route' => $r->governanceRoute?->name,
                    'Urgency' => $r->urgency ? ucfirst($r->urgency) : null,
                ];
            @endphp

            @foreach ($rows as $label => $value)
                <div class="px-4 py-3">
                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 text-sm {{ $value ? 'text-slate-900' : 'text-slate-400' }}">
                        {{ $value ?: '—' }}
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- ---- Narrative sections ----------------------------------------- --}}
    {{--
        Only sections with content are rendered.

        A page of twelve headings reading "—" for a draft reads as broken. Showing
        what exists tells the requestor exactly how far they have got.
    --}}
    @php
        $sections = array_filter([
            'Business need' => $r->business_need,
            'Business plan' => $r->business_plan_reference
                ? 'In business plan — '.$r->business_plan_reference
                : null,
            'Justification (not in plan)' => $r->adhoc_justification,
            'Value proposition' => $r->value_proposition,
            'Impact if not implemented' => $r->impact_if_not_implemented,
            'Risks' => $r->risk_summary,
            'Mitigation' => $r->mitigation_plan,
            'Dependencies and constraints' => $r->dependencies_constraints,
            'In scope' => $r->in_scope,
            'Out of scope' => $r->out_of_scope,
            'Resources required' => $r->forecast_resources,
        ], fn ($v) => filled($v));
    @endphp

    @if ($sections || $r->budget_amount !== null)
        <div class="mt-5 space-y-4">
            @if ($r->budget_amount !== null)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Budget</h2>
                    <p class="mt-1 text-lg font-semibold text-slate-900">
                        RM {{ number_format((float) $r->budget_amount, 2) }}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ collect([$r->funding_type, $r->budget_source, $r->budget_code])->filter()->join(' · ') ?: 'No source recorded' }}
                    </p>

                    @if ($r->proposed_start_date || $r->target_completion_date)
                        <p class="mt-2 text-xs text-slate-500">
                            {{ $r->proposed_start_date?->format('d M Y') ?? 'No start date' }}
                            →
                            {{ $r->target_completion_date?->format('d M Y') ?? 'No target date' }}
                        </p>
                    @endif
                </div>
            @endif

            @foreach ($sections as $heading => $body)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $heading }}</h2>
                    {{-- whitespace-pre-line so paragraphs the requestor typed are kept. --}}
                    <p class="mt-1.5 whitespace-pre-line text-sm text-slate-700">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ---- Approval trail --------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
            Approval trail
        </h2>

        @if ($r->approvalTasks->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500">
                No approvals have been requested yet.
            </p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($r->approvalTasks as $task)
                    @php
                        $decided = $task->decided_at !== null;
                        $overdue = ! $decided && $task->due_at?->isPast();
                    @endphp
                    <li class="flex items-start gap-3 px-4 py-3">
                        <span class="mt-0.5 text-sm"
                              aria-hidden="true">{{ $decided ? '✓' : ($overdue ? '⚠' : '○') }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-900">
                                <span class="font-medium">
                                    {{ \App\Enums\WorkflowStage::from($task->stage)->label() }}
                                </span>
                                <span class="text-slate-500">
                                    — {{ $task->approver?->name ?? 'unassigned' }}
                                </span>
                            </p>

                            @if ($decided)
                                <p class="mt-0.5 text-xs text-slate-600">
                                    {{ $task->decision?->label() ?? 'Decided' }}
                                    on {{ $task->decided_at->format('d M Y') }}
                                </p>
                            @elseif ($task->due_at)
                                <p class="mt-0.5 text-xs {{ $overdue ? 'font-medium text-red-700' : 'text-slate-500' }}">
                                    Due {{ $task->due_at->format('d M Y') }}
                                </p>
                            @endif

                            @if ($task->comments)
                                <p class="mt-1 whitespace-pre-line text-xs text-slate-600">{{ $task->comments }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- ---- History ----------------------------------------------------- --}}
    {{--
        The workflow history rather than the audit log. This answers "how did my
        request move?" — the question a requestor actually asks. The audit log
        answers "who changed what value", which is a compliance question and lives
        in the admin area.
    --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
            History
        </h2>

        @if ($r->histories->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500">
                Nothing has happened to this request yet.
            </p>
        @else
            <ol class="divide-y divide-slate-100">
                @foreach ($r->histories as $entry)
                    @php
                        /*
                         * `from_stage` and `to_stage` hold stage codes OR a status value,
                         * because a return records a status and a system transition records
                         * a stage. Mapped wherever possible, and shown raw only when the
                         * value is neither — better than printing a code and calling it a
                         * name, and better than hiding the transition entirely.
                         */
                        $label = function (?string $value): ?string {
                            if ($value === null) {
                                return null;
                            }

                            return \App\Enums\WorkflowStage::tryFrom($value)?->label()
                                ?? \App\Enums\RequestStatus::tryFrom($value)?->label()
                                ?? $value;
                        };
                    @endphp

                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ Str::headline($entry->action) }}</p>
                            <p class="text-xs text-slate-500">{{ $entry->created_at->format('d M Y, H:i') }}</p>
                        </div>

                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $entry->performedBy?->name ?? 'System' }}
                            @if ($entry->from_stage || $entry->to_stage)
                                · {{ $label($entry->from_stage) ?? 'start' }}
                                → {{ $label($entry->to_stage) }}
                            @endif
                        </p>

                        @if ($entry->remarks)
                            <p class="mt-1 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                                {{ $entry->remarks }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
