@php
    use App\Enums\Decision;
@endphp

<div class="mx-auto max-w-4xl">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">My approvals</h1>
            <p class="mt-1 text-sm text-slate-500">
                Requests awaiting your decision.
            </p>
        </div>

        <a href="{{ route('delegations.index') }}" wire:navigate
           class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Going away?
        </a>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{--
        Acting-for banner.

        A delegate needs to know whose decisions they are about to make, and the
        approvers need to know who authorised it. Without this a delegate could
        approve something without realising the authority is borrowed, and the
        requestor would see a name they do not recognise on their own request.
    --}}
    @if ($myDelegations->isNotEmpty())
        <div class="mt-4 rounded-xl bg-indigo-50 px-4 py-3 ring-1 ring-indigo-200">
            <p class="text-sm font-medium text-indigo-900">You are acting for someone</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($myDelegations as $delegation)
                    <li class="text-xs text-indigo-800">
                        {{ $delegation->approver?->name }} — until {{ $delegation->ends_at->format('d M Y') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-5 flex items-center gap-3">
        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <input type="checkbox" wire:model.live="overdueOnly"
                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span class="text-slate-700">Overdue only</span>
        </label>

        @if ($overdueCount > 0)
            <span class="text-sm font-medium text-red-700">
                ⚠ {{ $overdueCount }} past target
            </span>
        @endif
    </div>

    {{-- ---- The queue ---------------------------------------------------- --}}
    @if ($tasks->isEmpty())
        <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            @if ($overdueOnly)
                <p class="text-sm font-medium text-slate-700">Nothing is overdue</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Every request waiting on you is still within its target date.
                </p>
            @else
                <p class="text-sm font-medium text-slate-700">Nothing is waiting on your decision</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Requests appear here when they reach a stage you approve. If you are
                    going away, nominate a delegate so they do not stall.
                </p>
            @endif
        </div>
    @else
        <ul class="mt-5 space-y-3">
            @foreach ($tasks as $task)
                @php
                    $overdue = $task->isOverdue();
                    $request = $task->request;
                    // Computed from the ACTIVE delegation, not `delegated_from_id`,
                    // which is only written once a decision has been made.
                    $actingForName = $actingFor[$task->id] ?? null;
                @endphp

                <li class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 {{ $overdue ? 'ring-red-200' : 'ring-slate-200' }}">
                    <div class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('requests.show', $request) }}" wire:navigate
                                   class="font-medium text-slate-900 hover:text-indigo-600">
                                    {{ $request->title }}
                                </a>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $request->request_no }}</p>
                            </div>

                            <x-ui.status :status="$request->statusEnum()" />
                        </div>

                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                            <div>
                                <dt class="inline text-slate-500">Requestor:</dt>
                                <dd class="inline text-slate-700">{{ $request->requestor?->name ?? '—' }}</dd>
                            </div>

                            @if ($request->department)
                                <div>
                                    <dt class="inline text-slate-500">Department:</dt>
                                    <dd class="inline text-slate-700">{{ $request->department->name }}</dd>
                                </div>
                            @endif

                            @if ($request->tier)
                                <div>
                                    <dt class="inline text-slate-500">Tier:</dt>
                                    <dd class="inline text-slate-700">{{ $request->tier->name }}</dd>
                                </div>
                            @endif

                            <div>
                                <dt class="inline text-slate-500">Your stage:</dt>
                                <dd class="inline font-medium text-slate-700">
                                    {{ $task->stageEnum()?->label() ?? $task->stage }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            @if ($task->due_at)
                                <span class="text-xs {{ $overdue ? 'font-semibold text-red-700' : 'text-slate-500' }}">
                                    {{-- An icon as well as a colour: colour alone is not a signal. --}}
                                    @if ($overdue) ⚠ Overdue since @else Target @endif
                                    {{ $task->due_at->format('d M Y') }}
                                </span>
                            @endif

                            {{--
                                "Acting for" is shown ON THE TASK, not only in the banner
                                above. A delegate scanning a long queue needs to see which
                                decisions are borrowed authority, per row.
                            --}}
                            @if ($actingForName)
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-900 ring-1 ring-amber-200">
                                    Acting for {{ $actingForName }}
                                </span>
                            @endif

                            <button type="button" wire:click="startDecision({{ $task->id }})"
                                    class="ml-auto rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
                                Decide
                            </button>
                        </div>
                    </div>

                    {{-- ---- The decision panel --------------------------------- --}}
                    @if ($deciding && $deciding->id === $task->id)
                        <div class="border-t border-slate-200 bg-slate-50 p-4">
                            <h2 class="text-sm font-semibold text-slate-900">Your decision</h2>

                            {{-- What is being decided, restated. An approver should not
                                 have to scroll back to check what they are approving. --}}
                            <div class="mt-2 rounded-lg bg-white p-3 text-xs ring-1 ring-slate-200">
                                <p class="text-slate-700">{{ $request->business_need }}</p>
                                @if ($request->budget_amount !== null)
                                    <p class="mt-2 font-medium text-slate-900">
                                        Budget: RM {{ number_format((float) $request->budget_amount, 2) }}
                                    </p>
                                @endif
                            </div>

                            @error('decision')
                                <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-800" role="alert">
                                    {{ $message }}
                                </p>
                            @enderror

                            <div class="mt-3 space-y-3">
                                <div>
                                    <label for="decision" class="block text-sm font-medium text-slate-700">
                                        Decision <span class="text-red-600">*</span>
                                    </label>
                                    <select id="decision" wire:model.live="decision"
                                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                        <option value="">Select a decision…</option>
                                        @foreach ($decisions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('decision')
                                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{--
                                    Conditions appear only for "approved with conditions".
                                    An approval with conditions and no conditions is a
                                    contradiction — the condition would live only in
                                    somebody's memory.
                                --}}
                                @if ($decision === Decision::ApprovedWithConditions->value)
                                    <div>
                                        <label for="conditions" class="block text-sm font-medium text-slate-700">
                                            Conditions <span class="text-red-600">*</span>
                                        </label>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            Recorded against this decision and shown at closure. Without
                                            them, "with conditions" means nothing.
                                        </p>
                                        <textarea id="conditions" wire:model="conditions" rows="2"
                                                  class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                                        @error('conditions')
                                            <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

                                <div>
                                    <label for="comments" class="block text-sm font-medium text-slate-700">
                                        Comment
                                        {{-- The asterisk appears only when the comment is
                                             actually required, so its presence means something. --}}
                                        @if (in_array($decision, [Decision::Returned->value, Decision::Rejected->value], true))
                                            <span class="text-red-600">*</span>
                                        @endif
                                    </label>

                                    @if ($decision === Decision::Returned->value)
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            Say what needs to change. The requestor is sent back to the
                                            stage that returned it, so this comment is their whole brief.
                                        </p>
                                    @elseif ($decision === Decision::Rejected->value)
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            This ends the request. The comment is the only record of why.
                                        </p>
                                    @endif

                                    <textarea id="comments" wire:model="comments" rows="3"
                                              class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea>
                                    @error('comments')
                                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" wire:click="recordDecision" wire:loading.attr="disabled"
                                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="recordDecision">Record decision</span>
                                    <span wire:loading wire:target="recordDecision">Recording…</span>
                                </button>

                                <button type="button" wire:click="cancelDecision"
                                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
