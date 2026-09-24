@php
    $today = now()->startOfDay();
@endphp

<div class="mx-auto max-w-4xl">

    <a href="{{ route('approvals.index') }}" wire:navigate
       class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-700">
        <span aria-hidden="true">←</span> My approvals
    </a>

    <div class="mt-4">
        <h1 class="text-xl font-semibold text-slate-900">Delegations</h1>
        <p class="mt-1 text-sm text-slate-500">
            Nominate somebody to decide on your behalf while you are away, so requests
            do not stall waiting on you.
        </p>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{-- ---- New delegation ---------------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Arrange cover</h2>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            {{-- Only an administrator chooses whose authority is being delegated.
                 For everyone else it is their own, and the field is fixed. --}}
            @if ($isAdmin)
                <div>
                    <label for="approver_id" class="block text-sm font-medium text-slate-700">
                        Approver who is away <span class="text-red-600">*</span>
                    </label>
                    <select id="approver_id" wire:model="approver_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div>
                    <label class="block text-sm font-medium text-slate-700">Approver who is away</label>
                    <input type="text" value="{{ auth()->user()->name }}" disabled
                           class="mt-1 block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                    <p class="mt-0.5 text-xs text-slate-500">
                        You can only delegate your own approval authority.
                    </p>
                </div>
            @endif

            <div>
                <label for="delegate_id" class="block text-sm font-medium text-slate-700">
                    Acting approver <span class="text-red-600">*</span>
                </label>
                <select id="delegate_id" wire:model="delegate_id"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <option value="">Select a person…</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
                @error('delegate_id')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="starts_at" class="block text-sm font-medium text-slate-700">
                    From <span class="text-red-600">*</span>
                </label>
                <input id="starts_at" type="date" wire:model="starts_at"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('starts_at')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="ends_at" class="block text-sm font-medium text-slate-700">
                    Until <span class="text-red-600">*</span>
                </label>
                {{--
                    An end date is required, deliberately.

                    A delegation with no end date is a permanent transfer of authority
                    that nobody remembers to undo — and that is how the current process
                    ended up with no delegation in use at all.
                --}}
                <p class="mt-0.5 text-xs text-slate-500">
                    Required. Cover that never expires is authority nobody remembers to take back.
                </p>
                <input id="ends_at" type="date" wire:model="ends_at"
                       class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                @error('ends_at')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="reason" class="block text-sm font-medium text-slate-700">Reason</label>
                <input id="reason" type="text" wire:model="reason" placeholder="e.g. Annual leave"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>
        </div>

        <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
            <span wire:loading.remove wire:target="save">Save delegation</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

    {{-- ---- Existing delegations --------------------------------------- --}}
    <div class="mt-5 rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-900">
            {{ $isAdmin ? 'All delegations' : 'Your delegations' }}
        </h2>

        @if ($delegations->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500">
                No delegations have been arranged.
            </p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($delegations as $delegation)
                    @php
                        $active = $delegation->isActive();
                        $expired = $delegation->ends_at && $delegation->ends_at->lt($today) && $delegation->revoked_at === null;
                    @endphp

                    <li class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-900">
                                <span class="font-medium">{{ $delegation->delegate?->name }}</span>
                                <span class="text-slate-500">acts for</span>
                                <span class="font-medium">{{ $delegation->approver?->name }}</span>
                            </p>

                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $delegation->starts_at?->format('d M Y') }}
                                →
                                {{ $delegation->ends_at?->format('d M Y') }}
                                @if ($delegation->reason) · {{ $delegation->reason }} @endif
                            </p>

                            @if ($isAdmin && $delegation->createdBy)
                                <p class="mt-0.5 text-xs text-slate-400">
                                    Arranged by {{ $delegation->createdBy->name }}
                                </p>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            {{--
                                Two different states, labelled differently.

                                "Expired" and "Revoked" are not the same: one ran its
                                course, the other was stopped early. Collapsing them
                                would lose why the authority ended.
                            --}}
                            @if ($delegation->revoked_at)
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
                                    Revoked
                                </span>
                            @elseif ($active)
                                <span class="rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-800 ring-1 ring-green-200">
                                    In force
                                </span>
                            @elseif ($expired)
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
                                    Expired
                                </span>
                            @else
                                <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-800 ring-1 ring-blue-200">
                                    Scheduled
                                </span>
                            @endif

                            @if (! $delegation->revoked_at && $delegation->ends_at?->gte($today))
                                <button type="button" wire:click="revoke({{ $delegation->id }})"
                                        wire:confirm="Revoke this delegation? Decisions already made under it are unaffected."
                                        class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                    Revoke
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Revoking stops the delegation taking effect. It does not erase it — decisions
        already made under it record who acted and who they acted for.
    </p>
</div>
