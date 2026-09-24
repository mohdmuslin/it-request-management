<?php

namespace App\Livewire\Delegations;

use App\Models\Delegation;
use App\Models\User;
use App\Services\AuditService;
use Livewire\Component;

/**
 * Delegation management.
 *
 * WHY THIS SCREEN EXISTS AT ALL
 *
 * The current process has no delegation, so an approver's leave stops every request
 * waiting on them. That is the single point of failure in the existing flow, and a
 * feature that exists only in the schema does not fix it — somebody has to be able
 * to set cover up before they go.
 *
 * WHO CAN SET IT UP
 *
 * The approver themselves, for their own authority. An administrator can also do it,
 * because the alternative is a request stalled behind somebody who has already left
 * and cannot be reached. An approver may NOT delegate somebody else's authority.
 */
class Index extends Component
{
    public ?int $delegate_id = null;

    public ?string $starts_at = null;

    public ?string $ends_at = null;

    public string $reason = '';

    public string $flash = '';

    /** An administrator setting cover on somebody else's behalf. */
    public ?int $approver_id = null;

    public function mount(): void
    {
        // Defaulted to today: a delegation is almost always arranged because
        // somebody is about to be away, so the start is nearly always now.
        $this->starts_at = now()->toDateString();
        $this->approver_id = auth()->id();
    }

    public function save(): void
    {
        $user = auth()->user();

        /*
         * An administrator may act for anybody; everyone else only for themselves.
         *
         * Forced rather than merely validated: if this were a form field a
         * non-administrator could post another user's id and delegate authority
         * they do not hold.
         */
        $approverId = $user->isAdministrator() && $this->approver_id
            ? $this->approver_id
            : $user->id;

        $this->validate([
            'delegate_id' => [
                'required', 'integer', 'exists:users,id',
                // Nobody can act for themselves; it is a no-op that would clutter
                // the queue with an "acting for" marker on their own decisions.
                'different:approver_id',
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'delegate_id.different' => 'A delegate must be a different person from the approver.',
            'ends_at.after_or_equal' => 'The end date cannot be before the start date.',
        ]);

        $delegation = Delegation::create([
            'approver_id' => $approverId,
            'delegate_id' => $this->delegate_id,
            'created_by' => $user->id,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'reason' => $this->reason ?: null,
        ]);

        app(AuditService::class)->record('delegation_created', $delegation, null, $delegation->getAttributes());

        $this->reset('delegate_id', 'reason', 'ends_at');
        $this->starts_at = now()->toDateString();
        $this->flash = 'Delegation saved. Requests assigned during this period can be decided by your delegate.';
        $this->resetErrorBag();
    }

    /**
     * Revoke a delegation.
     *
     * Sets a timestamp rather than deleting. Decisions already made under it cite
     * this row through `approval_tasks.delegated_from_id`, and removing it would
     * leave those decisions pointing at nothing — BR-008 requires the arrangement
     * that authorised the act to remain explainable.
     */
    public function revoke(int $id): void
    {
        $delegation = Delegation::findOrFail($id);

        $mayRevoke = $delegation->approver_id === auth()->id()
            || $delegation->created_by === auth()->id()
            || auth()->user()->isAdministrator();

        abort_unless($mayRevoke, 403, 'You cannot revoke this delegation.');

        if ($delegation->revoked_at !== null) {
            return;
        }

        $delegation->forceFill([
            'revoked_at' => now(),
            'revoked_by' => auth()->id(),
        ])->save();

        app(AuditService::class)->record('delegation_revoked', $delegation, null, $delegation->getAttributes());

        $this->flash = 'Delegation revoked. It no longer authorises any decisions.';
    }

    public function render()
    {
        $user = auth()->user();
        $isAdmin = $user->isAdministrator();

        // An administrator sees every delegation; everyone else sees the ones they
        // are party to. Not "everyone sees everything" — who is covering for whom
        // is not public information.
        $delegations = Delegation::query()
            ->when(! $isAdmin, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('approver_id', $user->id)
                ->orWhere('delegate_id', $user->id)))
            ->with(['approver:id,name', 'delegate:id,name', 'createdBy:id,name'])
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();

        return view('livewire.delegations.index', [
            'delegations' => $delegations,
            'people' => User::query()->active()->orderBy('name')->get(['id', 'name']),
            'isAdmin' => $isAdmin,
        ])->layout('components.layouts.app', ['title' => 'Delegations']);
    }
}
