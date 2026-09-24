<?php

namespace App\Models;

use App\Enums\BusinessPlanStatus;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An IT request — the aggregate root.
 *
 * Every child row belongs to exactly one of these: approvals, recommendations,
 * documents, comments, history.
 *
 * TWO COLUMNS WORTH UNDERSTANDING BEFORE EDITING ANYTHING
 *
 * `status` and `current_stage` are related but not the same. The status is where
 * the request is; the stage is the step of the process it is on. `returned_from_stage`
 * is what makes a return resume at the stage that returned it rather than
 * restarting the chain — see BusinessPlanStatus and WorkflowStage for the halves
 * of that rule.
 */
class ItRequest extends Model
{
    use HasFactory;

    protected $table = 'it_requests';

    protected $fillable = [
        'request_no',
        'title',
        'request_date',
        'requestor_id',
        'department_id',
        'division_id',
        'project_owner_id',
        'project_sponsor_id',
        'proposed_tier_id',
        'proposed_classification_id',
        'tier_id',
        'classification_id',
        'governance_route_id',
        'status',
        'current_stage',
        'business_need',
        'business_plan_status',
        'business_plan_reference',
        'adhoc_justification',
        'budget_amount',
        'budget_source',
        'budget_code',
        'funding_type',
        'urgency',
        'urgency_justification',
        'risk_summary',
        'mitigation_plan',
        'dependencies_constraints',
        'impact_if_not_implemented',
        'value_proposition',
        'in_scope',
        'out_of_scope',
        'proposed_start_date',
        'target_completion_date',
        'forecast_resources',
        'submitted_at',
        'closed_at',
        'outcome',
        'returned_from_stage',
    ];

    /**
     * `request_no`, `status`, `current_stage` and the assigned tier/classification
     * are deliberately ABSENT from $fillable.
     *
     * BR-009: system-managed fields shall not be editable through ordinary request
     * screens. Leaving them unfillable means a form cannot set them even if a
     * view is changed to post them — the guard is in the model, not the template.
     */
    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'proposed_start_date' => 'date',
            'target_completion_date' => 'date',
            'budget_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'business_plan_status' => BusinessPlanStatus::class,
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function projectOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_owner_id');
    }

    public function projectSponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_sponsor_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** What IT Governance assigned. Null until completeness review. */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    /** What the requestor proposed. Kept so a later dispute has an answer. */
    public function proposedTier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'proposed_tier_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class);
    }

    public function proposedClassification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'proposed_classification_id');
    }

    public function governanceRoute(): BelongsTo
    {
        return $this->belongsTo(GovernanceRoute::class);
    }

    public function approvalTasks(): HasMany
    {
        return $this->hasMany(ApprovalTask::class)->orderBy('sequence');
    }

    /**
     * The single task awaiting a decision.
     *
     * At most one may exist — two would let two people decide the same stage and
     * silently overwrite each other. The service enforces it; a test asserts it.
     */
    public function pendingApprovalTask(): HasOne
    {
        return $this->hasOne(ApprovalTask::class)->whereNull('decided_at');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function consolidation(): HasOne
    {
        return $this->hasOne(RecommendationConsolidation::class);
    }

    public function committeeDecision(): HasOne
    {
        return $this->hasOne(CommitteeDecision::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at');
    }

    // ---- State helpers -----------------------------------------------------

    public function statusEnum(): RequestStatus
    {
        return RequestStatus::from($this->status);
    }

    public function stageEnum(): ?WorkflowStage
    {
        return $this->current_stage ? WorkflowStage::tryFrom($this->current_stage) : null;
    }

    /**
     * The field that is mandatory given the business plan answer.
     *
     * The concrete form of BR-003. Returns null when no answer has been given yet,
     * which a draft legitimately allows.
     */
    public function requiredBusinessPlanField(): ?string
    {
        return $this->business_plan_status?->requiredField();
    }

    /** Comments visible to the requestor, excluding internal governance notes. */
    public function publicComments(): HasMany
    {
        return $this->comments()->where('is_internal', false);
    }

    // ---- Scopes ------------------------------------------------------------

    /** Requests still in play, excluding terminal states. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            RequestStatus::Closed->value,
            RequestStatus::Withdrawn->value,
            RequestStatus::NotRecommended->value,
        ]);
    }

    public function scopeAwaitingStage(Builder $query, WorkflowStage $stage): Builder
    {
        return $query->where('current_stage', $stage->value);
    }

    /**
     * Requests this user may see.
     *
     * A requestor sees their own; an approver additionally sees anything they must
     * decide. Scope is applied in the query rather than filtered afterwards — a
     * filter that runs after the fetch is a filter somebody can forget.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(
            UserRole::Administrator,
            UserRole::GovernanceReviewer,
            UserRole::Hou,
            UserRole::CommitteeSecretariat,
            UserRole::Auditor,
        )) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('requestor_id', $user->id)
                ->orWhere('project_owner_id', $user->id)
                ->orWhere('project_sponsor_id', $user->id)
                ->orWhereHas('approvalTasks', fn (Builder $t) => $t->where('approver_id', $user->id));
        });
    }
}
