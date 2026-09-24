<?php

namespace App\Enums;

/**
 * Governance routes.
 *
 * THE MOST IMPORTANT THING ABOUT THIS ENUM
 *
 * The route is an OUTPUT, not an input. It is determined by the IT HOU during
 * consolidation, after the technical units have filed their recommendations — not
 * chosen by the requestor at submission, and not derived from the tier alone.
 *
 * That is why it has its own table rather than being a column on the request with
 * a default. A route recorded at submission would be a prediction; a route set at
 * consolidation is a decision.
 *
 * Only Full reaches the committee. That single fact is what the three routes
 * exist to express.
 */
enum GovernanceRoute: string
{
    case Light = 'light';
    case Moderate = 'moderate';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Moderate => 'Moderate',
            self::Full => 'Full',
        };
    }

    /**
     * Whether this route requires an IT Investment Committee decision.
     *
     * The routing rule, in one method. Light and Moderate complete without a
     * committee; Full cannot close without one.
     */
    public function requiresCommittee(): bool
    {
        return $this === self::Full;
    }

    /** A short explanation for the consolidation screen. */
    public function description(): string
    {
        return match ($this) {
            self::Light => 'No committee. Proceeds directly to approval.',
            self::Moderate => 'No committee. Proceeds directly to approval.',
            self::Full => 'Requires an IT Investment Committee decision.',
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
