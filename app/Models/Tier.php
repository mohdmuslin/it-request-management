<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A governance tier.
 *
 * Three exist today, and they are **two different kinds of thing** — which is why this model has
 * more shape than a lookup table normally would:
 *
 *   - **Tier 1 and Tier 2** are decided by cost. "RM50,000 and below" and "RM50,001 and above".
 *     The requestor picks one, and the application refuses a tier that contradicts the amount.
 *   - **Tier P** is a partnership or collaboration. It is not a band — a RM20,000 collaboration
 *     and a RM2,000,000 one are both Tier P — and it is a judgement, not an arithmetic result.
 *
 * So `budget_min`/`budget_max` are nullable (Tier P has neither) and `assignable_by` says who
 * chooses. See the migration for why modelling Tier P as an open-ended band would be wrong.
 *
 * Reference data rather than a bare enum, so an administrator can rename one, re-band it, or
 * change a threshold without a deployment.
 */
class Tier extends Model
{
    use HasFactory;

    /** The requestor chooses it, and the budget must agree. */
    public const BY_REQUESTOR = 'requestor';

    /** Governance assigns it. The requestor cannot select it at all. */
    public const BY_GOVERNANCE = 'governance';

    protected $fillable = [
        'code',
        'name',
        'description',
        'budget_min',
        'budget_max',
        'assignable_by',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            /*
             * Money as a STRING, not a float.
             *
             * `decimal(12,2)` and the brief's §5.2 forbids float storage for financial values.
             * Casting to `float` here would reintroduce the representation error the column type
             * exists to avoid — and it would do it in the comparison that decides the tier, so
             * RM50,000.00 could land the wrong side of a boundary it should sit exactly on.
             */
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
        ];
    }

    public function dueDayOverrides(): HasMany
    {
        return $this->hasMany(StageDueDay::class);
    }

    public function fieldRules(): HasMany
    {
        return $this->hasMany(TierFieldRule::class);
    }

    /** Whether budget determines this tier. False for a partnership. */
    public function isBudgetBased(): bool
    {
        return $this->budget_min !== null || $this->budget_max !== null;
    }

    /** Whether the requestor may select it. */
    public function isRequestorAssignable(): bool
    {
        return $this->assignable_by === self::BY_REQUESTOR;
    }

    /**
     * Whether an amount falls inside this tier's band.
     *
     * BOTH BOUNDS ARE INCLUSIVE and a null bound means unbounded, so the top band needs no
     * invented ceiling. A tier with neither bound is not budget-based and this returns false —
     * a partnership is never selected by arithmetic.
     *
     * The comparison is done on decimal strings via `bccomp`, not as floats. `50000.00 > 50000`
     * is reliable in PHP, but `0.1 + 0.2` is not, and this is the one comparison in the
     * application where being off by a representation error changes a governance outcome.
     */
    public function containsAmount(string|float|int|null $amount): bool
    {
        if ($amount === null || $amount === '' || ! $this->isBudgetBased()) {
            return false;
        }

        $value = number_format((float) $amount, 2, '.', '');

        if ($this->budget_min !== null && bccomp($value, (string) $this->budget_min, 2) < 0) {
            return false;
        }

        if ($this->budget_max !== null && bccomp($value, (string) $this->budget_max, 2) > 0) {
            return false;
        }

        return true;
    }

    /** For a dropdown or a table: "RM50,000.00 and below", "RM50,001.00 and above". */
    public function bandLabel(): string
    {
        if (! $this->isBudgetBased()) {
            return 'Not based on budget';
        }

        $money = fn (?string $v): string => 'RM'.number_format((float) $v, 2);

        if ($this->budget_min === null && $this->budget_max !== null) {
            return $money($this->budget_max).' and below';
        }

        if ($this->budget_min !== null && $this->budget_max === null) {
            return $money($this->budget_min).' and above';
        }

        return $money($this->budget_min).' – '.$money($this->budget_max);
    }

    /**
     * The name as the requestor sees it in the dropdown.
     *
     * `Tier 1 — RM50,000.00 and below`.
     *
     * The band is IN the option rather than beside it, because it is not supplementary
     * information — it is the thing that decides the answer, and the application refuses a tier
     * that contradicts the amount. A requestor choosing between "Tier 1" and "Tier 2" has to
     * already know the threshold; with the band shown, the choice is self-explanatory.
     *
     * A tier with no band returns its plain name, so a governance-assigned tier does not read as
     * "Tier P — Not based on budget" if it ever appears somewhere a name is listed.
     */
    public function bandedName(): string
    {
        return $this->isBudgetBased() ? "{$this->name} — {$this->bandLabel()}" : $this->name;
    }
}
