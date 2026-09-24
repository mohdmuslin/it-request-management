@php
    use App\Enums\RecommendationOutcome;
@endphp

<div class="mx-auto max-w-4xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Committee workspace</h1>
        <p class="mt-1 text-sm text-slate-500">
            Full-route requests awaiting an IT Investment Committee decision.
        </p>
    </div>

    @if ($requests->isEmpty())
        <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <p class="text-sm font-medium text-slate-700">Nothing is awaiting a committee decision</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                A request reaches the committee only when consolidation places it on the
                Full route.
            </p>
        </div>
    @else
        <ul class="mt-5 space-y-3">
            @foreach ($requests as $request)
                @php
                    $outstanding = $request->pendingApprovalTask;
                    $overdue = $outstanding?->due_at?->isPast();
                    $concerns = $request->recommendations
                        ->filter(fn ($rec) => RecommendationOutcome::tryFrom($rec->recommendation)?->isConcern());
                @endphp

                <li class="rounded-xl bg-white p-4 shadow-sm ring-1 {{ $overdue ? 'ring-red-200' : 'ring-slate-200' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('requests.show', $request) }}" wire:navigate
                               class="font-medium text-slate-900 hover:text-indigo-600">
                                {{ $request->title }}
                            </a>
                            <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $request->request_no }}</p>
                        </div>

                        @if ($outstanding?->due_at)
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 {{ $overdue
                                ? 'bg-red-50 text-red-800 ring-red-200'
                                : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                @if ($overdue) ⚠ Overdue @else Due @endif
                                {{ $outstanding->due_at->format('d M Y') }}
                            </span>
                        @endif
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
                        @if ($request->budget_amount !== null)
                            <div>
                                <dt class="inline text-slate-500">Budget:</dt>
                                <dd class="inline font-medium text-slate-900">
                                    RM {{ number_format((float) $request->budget_amount, 2) }}
                                </dd>
                            </div>
                        @endif
                    </dl>

                    {{--
                        The unit positions, summarised.

                        The committee decides on the technical advice, so it belongs on
                        the agenda rather than one click away. A unit advising against is
                        called out, because that is the thing a committee most needs to
                        see before it approves.
                    --}}
                    @if ($request->recommendations->isNotEmpty())
                        <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2">
                            <p class="text-xs font-medium text-slate-600">
                                Unit recommendations ({{ $request->recommendations->count() }})
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($request->recommendations as $rec)
                                    @php $outcome = RecommendationOutcome::tryFrom($rec->recommendation); @endphp
                                    <li class="text-xs">
                                        <span class="text-slate-600">{{ $rec->reviewUnit?->name }}:</span>
                                        <span class="{{ $outcome?->isConcern() ? 'font-semibold text-amber-800' : 'text-slate-700' }}">
                                            {{ $outcome?->label() ?? $rec->recommendation }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($concerns->isNotEmpty())
                                <p class="mt-1 text-xs font-medium text-amber-800">
                                    ⚠ {{ $concerns->count() }}
                                    {{ Str::plural('unit', $concerns->count()) }}
                                    advised against. Read the reasoning before deciding.
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-3">
                        <a href="{{ route('requests.show', $request) }}" wire:navigate
                           class="inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Open the request and record the decision
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
