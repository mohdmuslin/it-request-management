<div class="mx-auto max-w-6xl">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Reports</h1>
            <p class="mt-1 text-sm text-slate-500">
                Workload, aging, turnaround and outcomes for the requests you can see.
            </p>
        </div>

        {{--
            The export carries the CURRENT FILTERS.

            An export that ignored them would produce a file whose totals disagree with
            the screen it came from, and the recipient has no way to know which is
            wrong. The filter querystring is passed through for exactly that reason.
        --}}
        <a href="{{ route('reports.export', request()->query()) }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Export to CSV
        </a>
    </div>

    {{-- ---- Filters ------------------------------------------------------ --}}
    <div class="mt-5 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="from" class="block text-xs font-medium text-slate-600">From</label>
                <input id="from" type="date" wire:model.live="from"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="to" class="block text-xs font-medium text-slate-600">To</label>
                <input id="to" type="date" wire:model.live="to"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="status" class="block text-xs font-medium text-slate-600">Status</label>
                <select id="status" wire:model.live="status"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">All</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="stage" class="block text-xs font-medium text-slate-600">Stage</label>
                <select id="stage" wire:model.live="stage"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">All</option>
                    @foreach ($stages as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="tier_id" class="block text-xs font-medium text-slate-600">Tier</label>
                <select id="tier_id" wire:model.live="tier_id"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">All</option>
                    @foreach ($tiers as $tier)
                        <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="governance_route_id" class="block text-xs font-medium text-slate-600">Route</label>
                <select id="governance_route_id" wire:model.live="governance_route_id"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">All</option>
                    @foreach ($routeModels as $route)
                        <option value="{{ $route->id }}">{{ $route->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="department_id" class="block text-xs font-medium text-slate-600">Department</label>
                <select id="department_id" wire:model.live="department_id"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">All</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="search" class="block text-xs font-medium text-slate-600">Search</label>
                <input id="search" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Number or title…"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
        </div>

        <button type="button" wire:click="clearFilters"
                class="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-700">
            Clear filters
        </button>
    </div>

    {{-- ---- Headline totals --------------------------------------------- --}}
    {{--
        Every figure below is derived from the same filtered query as the table at the
        bottom, which is what makes UAT-014's "totals reconcile" true by construction
        rather than by careful maintenance.
    --}}
    <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">Requests in scope</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $outcomes['total'] }}</p>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">Still open</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $outcomes['open'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Not yet closed</p>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">Closed</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $outcomes['closed'] }}</p>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs text-slate-500">Overdue now</p>
            <p class="mt-1 text-2xl font-semibold {{ $overdue->count() > 0 ? 'text-red-700' : 'text-slate-900' }}">
                {{ $overdue->count() }}
            </p>
            @if ($overdue->count() > 0)
                <p class="mt-1 text-xs font-medium text-red-700">⚠ Past target date</p>
            @endif
        </div>
    </div>

    {{-- ---- Turnaround --------------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Turnaround for closed requests</h2>

        @if ($turnaround['count'] === 0)
            {{-- Stated as "no data", not as zero. "0 hours" reads as "we are
                 instant", which is the opposite of "we have not finished anything". --}}
            <p class="mt-2 text-sm text-slate-500">
                No requests have been closed within these filters, so there is no
                turnaround to report.
            </p>
        @else
            <dl class="mt-3 grid gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Median</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($turnaround['median_hours'], 1) }}h
                    </dd>
                    {{-- The median is the figure worth reading: one request left open
                         for two months drags the average away from every other. --}}
                    <p class="text-xs text-slate-400">The typical request</p>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Average</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($turnaround['average_hours'], 1) }}h
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Fastest</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($turnaround['fastest_hours'], 1) }}h
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Slowest</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">
                        {{ number_format($turnaround['slowest_hours'], 1) }}h
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">
                Measured in working hours between submission and closure, across
                {{ $turnaround['count'] }} {{ Str::plural('request', $turnaround['count']) }}.
            </p>
        @endif
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- ---- Workload by stage -------------------------------------- --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
                Workload by stage
            </h2>
            <ul class="divide-y divide-slate-100">
                @foreach ($byStage as $key => $row)
                    @if ($row['count'] > 0)
                        <li class="flex items-center justify-between px-4 py-2.5">
                            <span class="text-sm text-slate-600">{{ $row['label'] }}</span>
                            <span class="text-sm font-semibold text-slate-900">{{ $row['count'] }}</span>
                        </li>
                    @endif
                @endforeach
            </ul>
            <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                Total {{ array_sum(array_column($byStage, 'count')) }} —
                matches the requests in scope above.
            </p>
        </div>

        {{-- ---- Workload by status ------------------------------------- --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
                Workload by status
            </h2>
            @if (empty($byStatus))
                <p class="px-4 py-6 text-center text-sm text-slate-500">Nothing matches these filters.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($byStatus as $row)
                        <li class="flex items-center justify-between px-4 py-2.5">
                            <span class="text-sm text-slate-600">{{ $row['label'] }}</span>
                            <span class="text-sm font-semibold text-slate-900">{{ $row['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- ---- Aging -------------------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Aging of open requests</h2>
            {{--
                Business days, stated on the screen.

                A reader who assumes calendar days will think the numbers are wrong: a
                request raised on Friday and read on Monday is one business day old.
            --}}
            <p class="mt-0.5 text-xs text-slate-500">
                In working days, excluding weekends and public holidays.
            </p>
        </div>

        @if (array_sum(array_column($aging, 'count')) === 0)
            <p class="px-4 py-6 text-center text-sm text-slate-500">
                No open requests within these filters.
            </p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Age</th>
                        <th scope="col" class="px-4 py-2 font-medium">Count</th>
                        <th scope="col" class="px-4 py-2 font-medium">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @php $agingTotal = array_sum(array_column($aging, 'count')); @endphp
                    @foreach ($aging as $bucket)
                        <tr>
                            <td class="px-4 py-2.5 text-slate-600">{{ $bucket['label'] }}</td>
                            <td class="px-4 py-2.5 font-semibold text-slate-900">{{ $bucket['count'] }}</td>
                            <td class="px-4 py-2.5 text-slate-500">
                                {{ $agingTotal > 0 ? round($bucket['count'] / $agingTotal * 100) : 0 }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ---- Stage durations --------------------------------------------- --}}
    @if (! empty($stageDurations))
        <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">How long each stage takes</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    Where the time actually goes. A stage holding two requests for a month
                    is a bigger problem than one holding eight for a day.
                </p>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Stage</th>
                        <th scope="col" class="px-4 py-2 font-medium">Decisions</th>
                        <th scope="col" class="px-4 py-2 font-medium">Median</th>
                        <th scope="col" class="px-4 py-2 font-medium">Missed target</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($stageDurations as $row)
                        <tr>
                            <td class="px-4 py-2.5 text-slate-600">{{ $row['label'] }}</td>
                            <td class="px-4 py-2.5 text-slate-900">{{ $row['count'] }}</td>
                            <td class="px-4 py-2.5 text-slate-900">{{ number_format($row['median_hours'], 1) }}h</td>
                            <td class="px-4 py-2.5">
                                @if ($row['overdue'] > 0)
                                    <span class="font-medium text-red-700">{{ $row['overdue'] }}</span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ---- The rows behind the totals ---------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Requests</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                The rows the figures above are derived from, so they can be checked
                against each other.
            </p>
        </div>

        @if ($requests->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500">Nothing matches these filters.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">Request</th>
                            <th scope="col" class="px-4 py-2 font-medium">Requestor</th>
                            <th scope="col" class="px-4 py-2 font-medium">Status</th>
                            <th scope="col" class="px-4 py-2 font-medium">Raised</th>
                            <th scope="col" class="px-4 py-2 font-medium">Age</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($requests as $request)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('requests.show', $request) }}" wire:navigate
                                       class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $request->title }}
                                    </a>
                                    <div class="font-mono text-xs text-slate-500">{{ $request->request_no }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $request->requestor?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <x-ui.status :status="$request->statusEnum()" />
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">
                                    {{ $request->request_date?->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">
                                    @if ($request->statusEnum()->isOpen())
                                        {{ app(App\Services\ReportingService::class)->businessDaysSince($request) }}d
                                    @else
                                        <span class="text-slate-400">closed</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($outcomes['total'] > $requests->count())
                <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                    Showing the {{ $requests->count() }} most recent of {{ $outcomes['total'] }}.
                    The export contains all of them.
                </p>
            @endif
        @endif
    </div>

    {{-- ---- Overdue detail ----------------------------------------------- --}}
    @if ($overdue->isNotEmpty())
        <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
                Past target date
            </h2>
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">Request</th>
                        <th scope="col" class="px-4 py-2 font-medium">Stage</th>
                        <th scope="col" class="px-4 py-2 font-medium">Waiting on</th>
                        <th scope="col" class="px-4 py-2 font-medium">Late by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($overdue as $task)
                        <tr>
                            <td class="px-4 py-2.5">
                                <a href="{{ route('requests.show', $task->request) }}" wire:navigate
                                   class="font-medium text-indigo-700 hover:underline">
                                    {{ $task->request->request_no }}
                                </a>
                                <div class="text-xs text-slate-500">{{ $task->request->title }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">
                                {{ App\Enums\WorkflowStage::tryFrom($task->stage)?->label() ?? $task->stage }}
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $task->approver?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-2.5 font-medium text-red-700">
                                {{ max(1, (int) round($task->business_days_late)) }}d
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
