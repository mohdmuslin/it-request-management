<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who can use the system.
 *
 * Roles are held through `role_user` rather than as a column here. That matters
 * for the audit trail: the pivot records who granted a role and when, which
 * answers "who made them an administrator?" — a question a boolean column cannot
 * answer.
 */
#[Fillable([
    'employee_no',
    'entra_object_id',
    'name',
    'email',
    'password',
    'department_id',
    'division_id',
    'manager_id',
    'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot(['granted_by', 'granted_at']);
    }

    public function reviewUnits(): BelongsToMany
    {
        return $this->belongsToMany(ReviewUnit::class, 'review_unit_user')->withPivot('is_lead');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * The reporting line.
     *
     * Used for reporting and as an escalation target. Deliberately NOT the
     * approval mechanism — approvals use the Owner and Sponsor named on the
     * request, because a named approver is accountable and a role queue lets a
     * request sit unowned.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function requestsMade(): HasMany
    {
        return $this->hasMany(ItRequest::class, 'requestor_id');
    }

    public function requestsOwned(): HasMany
    {
        return $this->hasMany(ItRequest::class, 'project_owner_id');
    }

    public function requestsSponsored(): HasMany
    {
        return $this->hasMany(ItRequest::class, 'project_sponsor_id');
    }

    public function approvalTasks(): HasMany
    {
        return $this->hasMany(ApprovalTask::class, 'approver_id');
    }

    // ---- Role helpers ------------------------------------------------------

    public function hasRole(UserRole $role): bool
    {
        return $this->roles->contains('name', $role->value);
    }

    public function hasAnyRole(UserRole ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An auditor may read everything and change nothing.
     *
     * Consulted by the policies. Hiding a menu item is presentation; refusing the
     * write is the control.
     */
    public function isReadOnly(): bool
    {
        return $this->hasRole(UserRole::Auditor);
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(UserRole::Administrator);
    }

    /**
     * Whether this user may act as an approver at some stage.
     *
     * Owner and Sponsor are per-request roles, so this asks only about capability.
     * The caller checks the specific request — kept separate so the rule lives in
     * one place rather than being re-derived in every policy.
     */
    public function canApprove(): bool
    {
        return $this->hasAnyRole(
            UserRole::ProjectOwner,
            UserRole::ProjectSponsor,
            UserRole::GovernanceReviewer,
            UserRole::Hou,
            UserRole::CommitteeSecretariat,
        );
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
