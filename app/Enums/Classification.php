<?php

namespace App\Enums;

/**
 * Request classifications.
 *
 * Others is a real category rather than an escape hatch. The business gave a
 * concrete example — a project team purchasing tablets for project use — which is
 * neither a new system, an enhancement, nor a subscription.
 *
 * Which IT units must review a request depends on this value. That mapping lives
 * in the `classification_review_units` table, not here, so the real rule can be
 * corrected without a code change.
 */
enum Classification: string
{
    case NewSystem = 'new_system';
    case Enhancement = 'enhancement';
    case SubscriptionLicense = 'subscription_license';
    case Others = 'others';

    public function label(): string
    {
        return match ($this) {
            self::NewSystem => 'New System',
            self::Enhancement => 'Enhancement',
            self::SubscriptionLicense => 'Subscription / License',
            self::Others => 'Others',
        };
    }

    /**
     * The process flow routes on this value.
     *
     * New systems attract the widest review because they carry the most delivery
     * risk; a licence renewal attracts the narrowest. The specific unit mapping is
     * reference data — this is only the default assumption.
     */
    public function defaultEffectiveRisk(): string
    {
        return match ($this) {
            self::NewSystem => 'high',
            self::Enhancement => 'medium',
            self::SubscriptionLicense => 'low',
            self::Others => 'medium',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
