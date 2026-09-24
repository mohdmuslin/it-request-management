<?php

namespace App\Enums;

/**
 * Governance tiers.
 *
 * Confirmed against two independent sources: the process flow diagram, and the
 * SharePoint list schema which declares the choice set as "Tier 1; Tier 2; Tier P".
 * Tier P is Partnership.
 */
enum Tier: string
{
    case Tier1 = 'tier_1';
    case Tier2 = 'tier_2';
    case TierP = 'tier_p';

    public function label(): string
    {
        return match ($this) {
            self::Tier1 => 'Tier 1',
            self::Tier2 => 'Tier 2',
            self::TierP => 'Tier P (Partnership)',
        };
    }

    /** Short form for dense tables and exports. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Tier1 => 'T1',
            self::Tier2 => 'T2',
            self::TierP => 'TP',
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
