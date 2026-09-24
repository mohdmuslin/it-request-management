@php use App\Enums\WorkflowStage; @endphp

<div>
    <h1 class="text-xl font-semibold text-slate-900">Dashboard</h1>
    <p class="mt-1 text-sm text-slate-500">
        {{ auth()->user()->name }}, here is what needs your attention.
    </p>

    @if (count($cards) === 0)
        @php $hasRole = auth()->user()->roles->isNotEmpty(); @endphp

        {{--
            The empty state distinguishes two different situations, because telling
            a user the wrong one sends them to the wrong person.

            This block previously said "your account has no role" whenever the card
            list was empty — and an administrator's list was empty because no branch
            matched, so the most privileged user in the system was told they had no
            role. An empty collection is not the same as an empty role.
        --}}
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            @if ($hasRole)
                <p class="text-sm font-medium text-slate-700">Nothing needs your attention</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Your role gives you no outstanding work right now. Requests appear here as they
                    reach a stage you act on.
                </p>
            @else
                <p class="text-sm font-medium text-slate-700">Your account has no role yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    A role decides which screens you can use, so there is nothing to show. An
                    administrator needs to assign one — a Requestor role is enough to raise a
                    request.
                </p>
            @endif
        </div>
    @else
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                @php
                    $overdue = str_contains(strtolower($card['label']), 'overdue') && (int) $card['value'] > 0;
                @endphp

                @if ($card['route'])
                    <a href="{{ $card['route'] }}" wire:navigate
                       class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:ring-indigo-300">
                @else
                    <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                @endif

                    <p class="text-xs text-slate-500">{{ $card['label'] }}</p>
                    <p @class([
                        'mt-1 text-2xl font-semibold',
                        'text-red-700' => $overdue,
                        'text-slate-900' => ! $overdue,
                    ])>{{ $card['value'] }}</p>

                    @if ($card['note'])
                        <p class="mt-1 text-xs {{ $overdue ? 'font-medium text-red-700' : 'text-slate-500' }}">
                            {{-- An icon as well as a colour: colour alone is not a signal. --}}
                            @if ($overdue) ⚠ @endif{{ $card['note'] }}
                        </p>
                    @endif

                @if ($card['route'])
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-slate-900">Needs attention</h2>
                <span class="text-xs text-slate-500">Soonest due first</span>
            </div>

            @if ($needsAttention->isEmpty())
                <p class="mt-4 text-sm text-slate-500">
                    Nothing is waiting on a decision. Approvals and review tasks appear here as
                    they are assigned.
                </p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Request</th>
                                <th class="px-3 py-2 font-medium">Stage</th>
                                <th class="hidden px-3 py-2 font-medium sm:table-cell">Waiting on</th>
                                <th class="px-3 py-2 font-medium whitespace-nowrap">Due</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($needsAttention as $task)
                                @php $overdue = $task->isOverdue(); @endphp
                                <tr class="hover:bg-slate-50">
                                    <td class="px-3 py-2.5">
                                        <a href="{{ route('requests.show', $task->request) }}" wire:navigate
                                           class="font-medium text-indigo-700 hover:underline">
                                            {{ $task->request->request_no }}
                                        </a>
                                        <div class="text-xs text-slate-500">{{ $task->request->title }}</div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {{ $task->stage }}
                                        </span>
                                    </td>
                                    <td class="hidden px-3 py-2.5 text-slate-600 sm:table-cell">
                                        {{ $task->approver?->name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        @if ($task->due_at === null)
                                            <span class="text-xs text-slate-400">No target set</span>
                                        @elseif ($overdue)
                                            <span class="text-xs font-semibold text-red-700">
                                                ⚠ {{ (int) $task->due_at->diffInDays(now()) }} day(s) overdue
                                            </span>
                                        @else
                                            <span class="text-xs font-medium text-slate-600">
                                                {{ $task->due_at->format('j M') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
