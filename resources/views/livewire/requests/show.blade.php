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
    @if ($canSubmit || $canEdit || $canWithdraw || $canDecide)
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canEdit)
                <a href="{{ route('requests.create') }}" wire:navigate
                   class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Edit draft
                </a>
            @endif

            @if ($canSubmit)
                <button type="button" wire:click="submit" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="submit">Submit for approval</span>
                    <span wire:loading wire:target="submit">Submitting…</span>
                </button>
            @endif

            @if ($canDecide)
                <span class="rounded-lg bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 ring-1 ring-amber-200">
                    This is awaiting your decision
                </span>
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
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ Str::headline($entry->action) }}</p>
                            <p class="text-xs text-slate-500">{{ $entry->created_at->format('d M Y, H:i') }}</p>
                        </div>

                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $entry->performedBy?->name ?? 'System' }}
                            @if ($entry->from_stage || $entry->to_stage)
                                · {{ $entry->from_stage ?? 'start' }} → {{ $entry->to_stage }}
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
