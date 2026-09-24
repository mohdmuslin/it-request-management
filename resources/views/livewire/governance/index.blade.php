@php
    use App\Enums\RequestStatus;
    use App\Enums\WorkflowStage;
@endphp

<div class="mx-auto max-w-5xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Governance workspace</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ $total }} {{ Str::plural('request', $total) }} in the governance process.
        </p>
    </div>

    {{--
        Stage tabs, oldest work first within each.

        A tab that shows a count but no rows is the defect this avoids: counts and
        rows come from the same scoped set, so they cannot disagree.
    --}}
    <nav aria-label="Governance stages" class="mt-5">
        <ul class="flex flex-wrap gap-2">
            @foreach ($stages as $key => $label)
                @php $isCurrent = $key === $stage; @endphp
                <li>
                    <button type="button" wire:click="showStage('{{ $key }}')"
                            @if ($isCurrent) aria-current="true" @endif
                            @class([
                                'rounded-lg border px-3 py-2 text-sm transition',
                                'border-indigo-500 bg-indigo-50 font-semibold text-indigo-900' => $isCurrent,
                                'border-slate-200 bg-white text-slate-600 hover:border-slate-300' => ! $isCurrent,
                            ])>
                        {{ $label }}
                        <span @class([
                            'ml-1 rounded-full px-1.5 py-0.5 text-xs',
                            'bg-indigo-600 text-white' => $isCurrent && $counts[$key] > 0,
                            'bg-slate-200 text-slate-700' => ! $isCurrent && $counts[$key] > 0,
                            'bg-slate-100 text-slate-400' => $counts[$key] === 0,
                        ])>{{ $counts[$key] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </nav>

    @if ($requests->isEmpty())
        <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <p class="text-sm font-medium text-slate-700">
                Nothing is at {{ $stages[$stage] }}
            </p>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                Requests appear here as they reach this stage.
            </p>
        </div>
    @else
        <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Requests at {{ $stages[$stage] }}</caption>
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Request</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium sm:table-cell">Requestor</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Tier / classification</th>
                        <th scope="col" class="px-4 py-3 font-medium">Waiting on</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($requests as $request)
                        <tr class="align-top hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('requests.show', $request) }}" wire:navigate
                                   class="font-medium text-slate-900 hover:text-indigo-600">
                                    {{ $request->title }}
                                </a>
                                <div class="mt-0.5 font-mono text-xs text-slate-500">{{ $request->request_no }}</div>
                                <div class="mt-1 text-xs text-slate-500 sm:hidden">
                                    {{ $request->requestor?->name }}
                                </div>
                            </td>

                            <td class="hidden px-4 py-3 text-slate-600 sm:table-cell">
                                {{ $request->requestor?->name ?? '—' }}
                                @if ($request->department)
                                    <div class="text-xs text-slate-400">{{ $request->department->name }}</div>
                                @endif
                            </td>

                            <td class="hidden px-4 py-3 md:table-cell">
                                @if ($request->tier || $request->classification)
                                    <div class="text-slate-600">{{ $request->tier?->name ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $request->classification?->name ?? '—' }}</div>
                                @else
                                    {{-- Not yet assessed. Said plainly, because a blank
                                         cell reads as missing data rather than as a stage
                                         that has not happened. --}}
                                    <span class="text-xs text-slate-400">Not yet assessed</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if ($stage === WorkflowStage::TechnicalRecommendation->value)
                                    @php $missing = $outstanding[$request->id] ?? []; @endphp
                                    @if (empty($missing))
                                        {{-- Every unit has filed, so the request should have
                                             advanced. Showing it as complete rather than
                                             inventing a waiter. --}}
                                        <span class="text-xs text-green-700">All units have filed</span>
                                    @else
                                        <span class="text-xs font-medium text-amber-800">
                                            {{ count($missing) }} {{ Str::plural('unit', count($missing)) }}
                                        </span>
                                        <div class="mt-0.5 text-xs text-slate-500">{{ implode(', ', $missing) }}</div>
                                    @endif
                                @elseif ($stage === WorkflowStage::Consolidation->value)
                                    <span class="text-xs text-slate-500">IT HOU</span>
                                @elseif ($stage === WorkflowStage::CommitteeDecision->value)
                                    {{-- Named, because "the committee" is a body and the
                                         question a reader has is who can actually act. --}}
                                    <span class="text-xs text-slate-500">IT Investment Committee</span>
                                @elseif ($stage === WorkflowStage::Closure->value)
                                    <span class="text-xs text-slate-500">Closure</span>
                                @else
                                    <span class="text-xs text-slate-500">IT Governance</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
