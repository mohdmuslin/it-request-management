<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Division;
use App\Models\ReviewUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Users, roles and review-unit membership.
 *
 * WHY ACCOUNTS ARE DEACTIVATED, NEVER DELETED
 *
 * Every request, approval, recommendation and history row references a user. Deleting
 * one would either orphan those rows or cascade through them — and a trail that loses
 * the name of the person who approved something is not a trail.
 *
 * Deactivating removes the account from every picker and stops it signing in, while
 * leaving every record it touched intact and readable.
 *
 * WHY ROLE AND UNIT MEMBERSHIP ARE SEPARATE CONTROLS
 *
 * A Technical Reviewer holds the role AND belongs to a unit. The same role behaves
 * differently depending on the unit, and a reviewer in no unit can file no
 * recommendation — so the screen shows both, and says so when a role is held without
 * the membership that makes it useful.
 */
class Users extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'role', history: true)]
    public string $role = '';

    public string $flash = '';

    /** The account being edited, if the panel is open. */
    public ?int $editingUserId = null;

    public ?int $department_id = null;

    public ?int $division_id = null;

    public ?int $manager_id = null;

    /** Role names held by the account being edited. */
    public array $roles = [];

    /** Review unit ids the account belongs to. */
    public array $units = [];

    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $user = User::with(['roles', 'reviewUnits'])->findOrFail($id);

        $this->editingUserId = $user->id;
        $this->department_id = $user->department_id;
        // Cleared rather than carried over: a division belongs to a department, and
        // showing a division from a different department invites saving the pair.
        $this->division_id = $user->department_id ? $user->division_id : null;
        $this->manager_id = $user->manager_id;
        $this->roles = $user->roles->pluck('name')->all();
        $this->units = $user->reviewUnits->pluck('id')->all();
        $this->is_active = (bool) $user->is_active;

        $this->flash = '';
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingUserId = null;
        $this->reset('department_id', 'division_id', 'manager_id', 'roles', 'units');
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $user = User::findOrFail($this->editingUserId);

        $this->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id', 'different:editingUserId'],
            'roles' => ['array'],
            'roles.*' => ['string', 'in:'.implode(',', array_column(UserRole::cases(), 'value'))],
            'units' => ['array'],
            'units.*' => ['integer', 'exists:review_units,id'],
        ], [
            'manager_id.different' => 'An account cannot be its own manager.',
        ], attributes: [
            'department_id' => 'department',
            'division_id' => 'division',
            'manager_id' => 'manager',
        ]);

        $before = [
            'department_id' => $user->department_id,
            'division_id' => $user->division_id,
            'manager_id' => $user->manager_id,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles->pluck('name')->all(),
            'units' => $user->reviewUnits->pluck('id')->all(),
        ];

        /*
         * An administrator cannot deactivate or demote THEMSELVES.
         *
         * Removing the last administrator leaves the system with nobody able to
         * administer it — no roles, no reference data, no way back in except the
         * database. This is the one change on this screen that can lock everybody out.
         */
        $isSelf = $user->id === auth()->id();

        if ($isSelf && ! $this->is_active) {
            $this->addError('is_active', 'You cannot deactivate your own account.');

            return;
        }

        if ($isSelf && ! in_array(UserRole::Administrator->value, $this->roles, true)) {
            $this->addError('roles', 'You cannot remove your own administrator role. Ask another administrator to do it.');

            return;
        }

        $user->update([
            'department_id' => $this->department_id,
            'division_id' => $this->division_id,
            'manager_id' => $this->manager_id,
            'is_active' => $this->is_active,
        ]);

        // Roles carry `granted_at`, so the pivot is synced with a timestamp rather than
        // attached — the audit trail shows when a role was granted.
        $roleIds = Role::whereIn('name', $this->roles)->pluck('id', 'name');
        $sync = [];

        foreach ($this->roles as $name) {
            if (isset($roleIds[$name])) {
                $sync[$roleIds[$name]] = ['granted_at' => now()];
            }
        }

        $user->roles()->sync($sync);
        $user->reviewUnits()->sync($this->units);

        app(AuditService::class)->record(
            event: 'user.updated',
            subject: $user,
            old: $before,
            new: [
                'department_id' => $this->department_id,
                'division_id' => $this->division_id,
                'manager_id' => $this->manager_id,
                'is_active' => $this->is_active,
                'roles' => $this->roles,
                'units' => $this->units,
            ],
        );

        $this->flash = "Saved changes to {$user->name}.";
        $this->cancelEdit();
    }

    public function render()
    {
        $query = User::query()
            ->with(['roles', 'department:id,name', 'division:id,name', 'reviewUnits:id,name'])
            ->orderBy('name');

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

            $query->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('email', 'like', $term));
        }

        if ($this->role !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $this->role));
        }

        $editing = $this->editingUserId ? User::find($this->editingUserId) : null;

        return view('livewire.admin.users', [
            'users' => $query->paginate(20),
            'editing' => $editing,
            'roleOptions' => UserRole::options(),
            'unitOptions' => ReviewUnit::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'divisions' => $this->department_id
                ? Division::where('department_id', $this->department_id)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'managers' => User::query()->active()
                ->where('id', '!=', $this->editingUserId)
                ->orderBy('name')->get(['id', 'name']),
            'canBootstrapAdmin' => $this->adminCount(),
        ])->layout('components.layouts.app', ['title' => 'Users and roles']);
    }

    /** How many active administrators exist — shown so the last one is visible. */
    private function adminCount(): int
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Administrator->value))
            ->count();
    }

    /** Clear the division when the department changes, so a stale pair cannot be saved. */
    public function updatedDepartmentId(): void
    {
        $this->division_id = null;
    }
}
