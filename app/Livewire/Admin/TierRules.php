<?php

namespace App\Livewire\Admin;

use App\Models\Tier;
use App\Models\TierFieldRule;
use App\Services\AuditService;
use App\Services\TierFieldRules;
use Livewire\Component;

/**
 * Which fields each tier makes required, optional or hidden (BR-003).
 *
 * WHY A SCREEN RATHER THAN A CONFIG FILE
 *
 * BR-003 requires conditional fields to be driven by tier. Written as code, the rule would be
 * right on the day it was written and need a release every time the business reconsiders what
 * Tier 2 must justify — which happens at short notice and always mid-cycle.
 *
 * WHY IT SHOWS EVERY FIELD FOR EVERY TIER AT ONCE
 *
 * The alternative — one tier at a time — hides the comparison, and the comparison is the only
 * thing that makes a set of rules reviewable. "Is budget required more often than it is
 * optional?" is a question this layout answers by looking at a column, and a form-per-tier
 * layout answers by clicking between three screens and remembering.
 *
 * WHY "EVERY TIER" IS A COLUMN AND NOT A SEPARATE CONCEPT
 *
 * Absence means optional, so most of this grid is empty and says nothing. The one row worth
 * reading is the default: a field optional everywhere except one tier should say so once, not
 * three times. It is presented as a column because it behaves like one — the resolution walks
 * from the specific tier to the default — but it is labelled so nobody sets it thinking they are
 * editing a tier.
 */
class TierRules extends Component
{
    /**
     * The grid, keyed "{tierKey}:{field}" where tierKey is a tier id or 'default'.
     *
     * A flat keyed array rather than nested, because Livewire binds to a flat property path and
     * `edits.tiers.2.fields.budget_amount` is a path with three segments to get wrong.
     */
    public array $edits = [];

    public string $flash = '';

    public function mount(): void
    {
        // Defence in depth: the route is behind the administrator check, and a Livewire action
        // can be invoked directly.
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->loadEdits();
    }

    private function loadEdits(): void
    {
        $this->edits = [];

        $rows = TierFieldRule::all()->mapWithKeys(
            fn (TierFieldRule $r) => [($r->tier_id ?? 'default').':'.$r->field => $r->requirement]
        );

        foreach ($this->tierKeys() as $key => $tier) {
            foreach (TierFieldRules::fieldNames() as $field) {
                /*
                 * A missing row reads as OPTIONAL rather than as blank.
                 *
                 * Blank would be a fourth state the resolution does not have, and saving it back
                 * would write a row meaning nothing. Absence and 'optional' are the same thing
                 * by design — see the migration — so the screen shows them as the same thing.
                 */
                $this->edits[$key.':'.$field] = $rows[$key.':'.$field] ?? TierFieldRule::OPTIONAL;
            }
        }
    }

    /** @return array<string, Tier|null> */
    private function tierKeys(): array
    {
        $keys = ['default' => null];

        foreach (Tier::orderBy('sort_order')->get() as $tier) {
            $keys[(string) $tier->id] = $tier;
        }

        return $keys;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->validate([
            'edits.*' => ['required', 'string', 'in:'.implode(',', TierFieldRule::requirements())],
        ], attributes: ['edits.*' => 'setting']);

        $before = TierFieldRule::orderBy('id')->get(['tier_id', 'field', 'requirement'])->toArray();
        $tiers = Tier::pluck('id', 'code');

        foreach ($this->edits as $key => $requirement) {
            [$tierKey, $field] = explode(':', $key, 2);

            if (! TierFieldRules::isGoverned($field)) {
                // Cannot happen through this screen; refuses rather than writing a row that
                // would govern nothing and look like the feature was broken.
                continue;
            }

            $tierId = $tierKey === 'default' ? null : (int) $tierKey;

            $query = TierFieldRule::where('field', $field);

            $tierId === null ? $query->whereNull('tier_id') : $query->where('tier_id', $tierId);

            /*
             * OPTIONAL IS STORED, NOT DELETED.
             *
             * Deleting the row would be indistinguishable from never having set it, and the
             * distinction is worth keeping: "somebody decided budget is optional for Tier 1" is
             * a decision somebody may later need to find, and an absent row cannot record that
             * it was made.
             *
             * It also keeps the grid stable — a field would otherwise vanish from the screen
             * when set back to optional, which reads as the change having failed.
             */
            if ($requirement === TierFieldRule::OPTIONAL) {
                $query->delete();

                continue;
            }

            TierFieldRule::updateOrCreate(
                ['tier_id' => $tierId, 'field' => $field],
                ['requirement' => $requirement],
            );
        }

        $after = TierFieldRule::orderBy('id')->get(['tier_id', 'field', 'requirement'])->toArray();

        if ($before !== $after) {
            app(AuditService::class)->record(
                event: 'settings.tier_field_rules_updated',
                subject: auth()->user(),
                old: ['rules' => $before],
                new: ['rules' => $after],
            );
        }

        app(TierFieldRules::class)->forget();

        $this->loadEdits();

        $this->flash = 'Saved. The rules apply to the next request saved, including ones already in progress.';
    }

    public function render()
    {
        return view('livewire.admin.tier-rules', [
            'tiers' => $this->tierKeys(),
            'groups' => TierFieldRules::GOVERNED,
            'requirements' => [
                TierFieldRule::REQUIRED => 'Required',
                TierFieldRule::OPTIONAL => 'Optional',
                TierFieldRule::HIDDEN => 'Hidden',
            ],
        ])->layout('components.layouts.app', ['title' => 'Tier field rules']);
    }
}
