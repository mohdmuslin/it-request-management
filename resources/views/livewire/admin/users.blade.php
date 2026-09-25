<div class="mx-auto max-w-6xl">

    <div>
        <h1 class="text-xl font-semibold text-slate-900">Users and roles</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ $users->total() }} {{ Str::plural('account', $users->total()) }}.
            Accounts are deactivated rather than deleted, because the history references them.
        </p>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200" role="status">
            {{ $flash }}
        </p>
    @endif

    {{--
        The generated password, shown ONCE.

        This is the only moment this value can be read — it is stored hashed and no
        screen can reveal it again. So the panel says that plainly, and offers a copy
        target, because a 24-character string transcribed by eye is a support call
        waiting to happen.
    --}}
    @if ($newPassword)
        <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-2 ring-indigo-300" role="status">
            <h2 class="text-sm font-semibold text-slate-900">Password for {{ $newPasswordFor }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                Copy this now and pass it to the account holder. It is stored hashed and
                <strong>cannot be shown again</strong>. They should change it after signing in.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="select-all rounded-lg bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100">{{ $newPassword }}</code>
                <button type="button" wire:click="dismissPassword"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    I have it — hide
                </button>
            </div>
        </div>
    @endif

    {{-- The last administrator is a lockout risk worth making visible. --}}
    @if ($canBootstrapAdmin <= 1)
        <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            There is {{ $canBootstrapAdmin }} active administrator.
            @if ($canBootstrapAdmin === 1)
                If that account is deactivated or loses the role, nobody can administer the
                system. Grant the role to a second account.
            @endif
        </p>
    @endif

    {{-- ---- Filters ------------------------------------------------------ --}}
    <div class="mt-5 flex flex-wrap gap-3">
        <div class="min-w-[240px] flex-1">
            <label for="search" class="sr-only">Search users</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search by name or email…"
                   class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        </div>

        <div>
            <label for="role" class="sr-only">Filter by role</label>
            <select id="role" wire:model.live="role"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="">All roles</option>
                @foreach ($roleOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="button" wire:click="startCreating"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Add an account
        </button>
    </div>

    {{-- ---- The new-account panel ---------------------------------------- --}}
    @if ($creating)
        <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-2 ring-indigo-200">
            <h2 class="text-base font-semibold text-slate-900">New account</h2>
            <p class="mt-1 text-sm text-slate-600">
                A password is generated and shown to you once. The account starts with
                <strong>no roles</strong> — grant them below, because a role is what decides
                what somebody can do.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="new_name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="new_name" type="text" wire:model="new_name" autocomplete="off"
                           class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @error('new_name')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new_email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="new_email" type="email" wire:model="new_email" autocomplete="off"
                           class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <p class="mt-1 text-xs text-slate-500">Used to sign in and to address notifications.</p>
                    @error('new_email')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new_employee_no" class="block text-sm font-medium text-slate-700">
                        Employee number <span class="font-normal text-slate-400">(optional)</span>
                    </label>
                    <input id="new_employee_no" type="text" wire:model="new_employee_no" autocomplete="off"
                           class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @error('new_employee_no')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-5 flex gap-2">
                <button type="button" wire:click="createUser" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="createUser">Create the account</span>
                    <span wire:loading wire:target="createUser">Creating…</span>
                </button>
                <button type="button" wire:click="cancelCreating"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ---- The edit panel ----------------------------------------------- --}}
    @if ($editing)
        <div class="mt-5 rounded-xl bg-white p-5 shadow-sm ring-2 ring-indigo-200">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $editing->name }}
                <span class="font-normal text-slate-500">{{ $editing->email }}</span>
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="department_id" class="block text-sm font-medium text-slate-700">Department</label>
                    <select id="department_id" wire:model.live="department_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Not set</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="division_id" class="block text-sm font-medium text-slate-700">Division</label>
                    @if ($department_id)
                        <select id="division_id" wire:model="division_id"
                                class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Not set</option>
                            @foreach ($divisions as $division)
                                <option value="{{ $division->id }}">{{ $division->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <select disabled
                                class="mt-1 block w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-400">
                            <option>Choose a department first</option>
                        </select>
                    @endif
                </div>

                <div>
                    <label for="manager_id" class="block text-sm font-medium text-slate-700">Manager</label>
                    <select id="manager_id" wire:model="manager_id"
                            class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">Not set</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    @error('manager_id')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-end">
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="is_active"
                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-slate-700">Active</span>
                    </label>
                    @error('is_active')
                        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-5">
                <p class="text-sm font-medium text-slate-700">Roles</p>
                @error('roles')
                    <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach ($roleOptions as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="roles" value="{{ $value }}"
                                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-slate-700">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-5">
                <p class="text-sm font-medium text-slate-700">Review units</p>
                {{--
                    Separate from roles, and the distinction matters: a Technical
                    Reviewer holds the role AND belongs to a unit. The same role behaves
                    differently depending on the unit, and a reviewer in no unit can file
                    no recommendation at all.
                --}}
                <p class="mt-0.5 text-xs text-slate-500">
                    A Technical Reviewer in no unit cannot file a recommendation — the units
                    assigned to a request are what decide who reviews it.
                </p>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach ($unitOptions as $unit)
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="units" value="{{ $unit->id }}"
                                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-slate-700">{{ $unit->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-5 flex gap-2">
                <button type="button" wire:click="save" wire:loading.attr="disabled"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button type="button" wire:click="cancelEdit"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- ---- The list ------------------------------------------------------ --}}
    <div class="mt-5 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">User accounts</caption>
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Account</th>
                    <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Department</th>
                    <th scope="col" class="px-4 py-3 font-medium">Roles</th>
                    <th scope="col" class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    <tr class="{{ $user->is_active ? '' : 'bg-slate-50' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-900">{{ $user->name }}</div>
                            <div class="text-xs text-slate-500">{{ $user->email }}</div>

                            @unless ($user->is_active)
                                <span class="mt-1 inline-block rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">
                                    Deactivated
                                </span>
                            @endunless
                        </td>

                        <td class="hidden px-4 py-3 text-slate-600 md:table-cell">
                            {{ $user->department?->name ?? '—' }}
                            @if ($user->division)
                                <div class="text-xs text-slate-400">{{ $user->division->name }}</div>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if ($user->roles->isEmpty())
                                {{-- Said plainly. A user with no role can sign in and reach
                                     almost nothing, and "why can I not see anything?" is
                                     otherwise answered only by an administrator noticing this. --}}
                                <span class="text-xs text-amber-800">No role — can reach almost nothing</span>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($user->roles as $role)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                            {{ $role->label ?? $role->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($user->reviewUnits->isNotEmpty())
                                <div class="mt-1 text-xs text-slate-500">
                                    Units: {{ $user->reviewUnits->pluck('name')->join(', ') }}
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="edit({{ $user->id }})"
                                    class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                Edit
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
