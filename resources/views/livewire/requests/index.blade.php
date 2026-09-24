@php
    use App\Enums\RequestStatus;

    $total = array_sum($counts);
@endphp

<div>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">My requests</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $total }} {{ Str::plural('request', $total) }} you can see.
            </p>
        </div>

        @can('create', App\Models\ItRequest::class)
            <a href="{{ route('requests.create') }}" wire:navigate
               class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                New request
            </a>
        @endcan
    </div>

    {{-- ---- Filters ---------------------------------------------------- --}}
    <div class="mt-5 flex flex-wrap items-center gap-3">
        <div class="min-w-[240px] flex-1">
            <label for="search" class="sr-only">Search requests</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search by number or title…"
                   class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>

        <div>
            <label for="status" class="sr-only">Filter by status</label>
            <select id="status" wire:model.live="status"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">All statuses ({{ $total }})</option>
                @foreach ($statuses as $value => $label)
                    @if (($counts[$value] ?? 0) > 0)
                        <option value="{{ $value }}">{{ $label }} ({{ $counts[$value] }})</option>
                    @endif
                @endforeach
            </select>
        </div>

        {{--
            "Waiting on me" is the view an approver opens the page for. It is a
            checkbox rather than the default, because for a requestor the useful
            default is everything they have raised.
        --}}
        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <input type="checkbox" wire:model.live="onlyMine"
                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span class="text-slate-700">Waiting on me</span>
        </label>

        @if ($status !== '' || $search !== '' || $onlyMine)
            <button type="button" wire:click="clearFilters"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                Clear
            </button>
        @endif
    </div>

    {{-- ---- The list --------------------------------------------------- --}}
    @if ($requests->isEmpty())
        <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            @if ($status !== '' || $search !== '' || $onlyMine)
                {{-- Distinguished from "you have none", because the action differs:
                     clearing a filter versus raising a request. --}}
                <p class="text-sm font-medium text-slate-700">No requests match these filters</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Try clearing the filters, or searching for part of the request number.
                </p>
            @else
                <p class="text-sm font-medium text-slate-700">You have no requests yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    A request is a formal proposal for a new system, an enhancement, a
                    subscription or licence, a partnership, or another IT initiative.
                </p>
                @can('create', App\Models\ItRequest::class)
                    <a href="{{ route('requests.create') }}" wire:navigate
                       class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Raise the first one
                    </a>
                @endcan
            @endif
        </div>
    @else
        {{--
            A table on wide screens, cards on narrow ones.

            Not a horizontally-scrolling table: on a phone the number and title
            scroll off, and the row becomes unreadable. The two layouts show the same
            fields, drawn from the same loop variables.
        --}}
        <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <table class="hidden w-full text-left text-sm md:table">
                <caption class="sr-only">Your IT requests</caption>
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Request</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">Owner</th>
                        <th scope="col" class="px-4 py-3 font-medium">Needed by</th>
                        <th scope="col" class="px-4 py-3 font-medium">Submitted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($requests as $request)
                        @php
                            $due = $request->pendingApprovalTask?->due_at;
                            $overdue = $due && $due->isPast();
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('requests.show', $request) }}" wire:navigate
                                   class="font-medium text-slate-900 hover:text-indigo-600">
                                    {{ $request->title }}
                                </a>
                                <div class="mt-0.5 font-mono text-xs text-slate-500">{{ $request->request_no }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.status :status="$request->statusEnum()" />
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $request->projectOwner?->name ?? '—' }}</td>
                            <td class="px-4 py-3 {{ $overdue ? 'font-medium text-red-700' : 'text-slate-600' }}">
                                {{-- An icon as well as a colour: colour alone is not a signal. --}}
                                @if ($overdue) ⚠ @endif
                                {{ $due?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $request->submitted_at?->format('d M Y') ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Narrow screens. --}}
            <ul class="divide-y divide-slate-100 md:hidden">
                @foreach ($requests as $request)
                    @php
                        $due = $request->pendingApprovalTask?->due_at;
                        $overdue = $due && $due->isPast();
                    @endphp
                    <li>
                        <a href="{{ route('requests.show', $request) }}" wire:navigate class="block px-4 py-4 hover:bg-slate-50">
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-medium text-slate-900">{{ $request->title }}</span>
                                <x-ui.status :status="$request->statusEnum()" />
                            </div>
                            <div class="mt-1 font-mono text-xs text-slate-500">{{ $request->request_no }}</div>
                            <div class="mt-2 text-xs text-slate-500">
                                Owner: {{ $request->projectOwner?->name ?? '—' }}
                                @if ($due)
                                    · <span class="{{ $overdue ? 'font-semibold text-red-700' : '' }}">
                                        Due {{ $due->format('d M Y') }}
                                    </span>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    @endif
</div>
