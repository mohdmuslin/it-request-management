<?php

namespace App\Livewire\Admin;

use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ReviewUnit;
use App\Models\Tier;
use App\Services\AuditService;
use App\Services\TierBands;
use Livewire\Component;

/**
 * Reference data — tiers, classifications, governance routes and review units.
 *
 * WHY THIS LIST IS ADMINISTERED RATHER THAN FREE-TYPED
 *
 * The current process types tier and classification as free text, and they drift —
 * "Tier 1", "tier1" and "Tier One" are three different values that report as three
 * different things. Maintaining the list here is what makes routing and reporting
 * reliable, because a value that is not on the list cannot be selected.
 *
 * WHY NOTHING HERE IS DELETED OUTRIGHT
 *
 * Every one of these tables is referenced by requests that already exist. Deleting a
 * tier would either orphan those requests or cascade the deletion through them. Rows
 * are DEACTIVATED — which removes them from the pickers while leaving every existing
 * request intact and readable — and the screen explains why.
 *
 * WHY `requires_committee` IS EDITABLE
 *
 * It is the routing rule. Stored as data rather than derived from the route's name so
 * that "does this reach the committee?" can be changed without a release — and so the
 * answer to why a request did or did not reach the committee is visible in the
 * database rather than buried in a `match` expression.
 */
class ReferenceData extends Component
{
    public string $flash = '';

    // ---- Add a row ---------------------------------------------------------

    public string $kind = 'tier';

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public bool $requires_committee = false;

    /** Inline edit state, keyed "{kind}:{id}". */
    public array $edits = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->loadEdits();
    }

    private function loadEdits(): void
    {
        $this->edits = [];

        foreach (Tier::orderBy('sort_order')->get() as $row) {
            $this->edits['tier:'.$row->id] = [
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
                /*
                 * The band, as two nullable strings.
                 *
                 * Empty means unbounded, not zero. "RM50,001 and above" has no ceiling, and
                 * recording one as 0 would make the tier cover nothing — the band would read
                 * "RM50,001 to RM0", which the overlap check would then have to cope with as a
                 * legitimate configuration.
                 */
                'budget_min' => $row->budget_min,
                'budget_max' => $row->budget_max,
                'assignable_by' => $row->assignable_by ?? Tier::BY_REQUESTOR,
            ];
        }

        foreach (Classification::orderBy('name')->get() as $row) {
            $this->edits['classification:'.$row->id] = ['name' => $row->name, 'is_active' => (bool) $row->is_active];
        }

        foreach (GovernanceRoute::orderBy('sort_order')->get() as $row) {
            $this->edits['route:'.$row->id] = [
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
                'requires_committee' => (bool) $row->requires_committee,
            ];
        }

        foreach (ReviewUnit::orderBy('sort_order')->get() as $row) {
            $this->edits['unit:'.$row->id] = ['name' => $row->name, 'is_active' => (bool) $row->is_active];
        }
    }

    /** The model class for a kind, or null if the kind is unknown. */
    private function modelFor(string $kind): ?string
    {
        return match ($kind) {
            'tier' => Tier::class,
            'classification' => Classification::class,
            'route' => GovernanceRoute::class,
            'unit' => ReviewUnit::class,
            default => null,
        };
    }

    public function add(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $model = $this->modelFor($this->kind);

        if (! $model) {
            $this->addError('kind', 'Choose what you are adding.');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            /*
             * The code is what the DATABASE stores and what code compares against.
             *
             * Constrained to lowercase words separated by underscores, because a code
             * with a space or a capital in it becomes a string that only matches if
             * every future comparison spells it identically — which is precisely how
             * the free-text drift this screen exists to prevent comes back.
             */
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:'.(new $model)->getTable().',code'],
        ], [
            'code.regex' => 'Use lowercase letters, numbers and underscores — for example new_system.',
            'code.unique' => 'That code is already in use.',
        ]);

        $attributes = [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'is_active' => true,
            'sort_order' => (int) $model::max('sort_order') + 1,
        ];

        if ($this->kind === 'route') {
            $attributes['requires_committee'] = $this->requires_committee;
        }

        $row = $model::create($attributes);

        app(AuditService::class)->record('reference_data.created', $row, null, $row->getAttributes());

        $this->reset('name', 'code', 'description', 'requires_committee');
        $this->loadEdits();

        $this->flash = 'Added. It is available in the pickers immediately.';
    }

    public function save(string $kind, int $id): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $model = $this->modelFor($kind);

        if (! $model) {
            return;
        }

        $row = $model::findOrFail($id);

        $this->validate([
            "edits.{$kind}:{$id}.name" => ['required', 'string', 'max:120'],
        ], attributes: ["edits.{$kind}:{$id}.name" => 'name']);

        $before = $row->getAttributes();

        $updates = ['name' => $this->edits["{$kind}:{$id}"]['name']];

        /*
         * `is_active` is stored as a boolean column, but a Livewire checkbox binds the
         * string "1"/"0" or a real bool depending on the wire format. Cast explicitly
         * rather than trusting the binding: on SQLite a string "0" is truthy, so a
         * deactivated row would stay active on one driver and not the other.
         */
        if (array_key_exists('is_active', $this->edits["{$kind}:{$id}"])) {
            $updates['is_active'] = (bool) $this->edits["{$kind}:{$id}"]['is_active'];
        }

        if ($kind === 'route' && array_key_exists('requires_committee', $this->edits["{$kind}:{$id}"])) {
            $updates['requires_committee'] = (bool) $this->edits["{$kind}:{$id}"]['requires_committee'];
        }

        if ($kind === 'tier') {
            $band = $this->tierBandFrom($id);

            if ($band === null) {
                return;   // the error has already been added
            }

            $updates = array_merge($updates, $band);
        }

        $row->update($updates);

        app(AuditService::class)->record('reference_data.updated', $row, $before, $row->fresh()->getAttributes());

        if ($kind === 'tier') {
            // The bands are cached per request and compared on every save.
            app(TierBands::class)->forget();
        }

        $this->loadEdits();

        $this->flash = 'Saved.';
    }

    /**
     * Read, validate and return a tier's band from the edit state, or null if it is invalid.
     *
     * WHY THE OVERLAP CHECK IS HERE AND NOT ONLY ON THE SCREEN
     *
     * The screen could show a warning without refusing, and then the first symptom of an overlap
     * is a requestor being told "a budget of RM50,000 falls into more than one tier" — a
     * configuration fault surfacing as a message on somebody else's form, with no indication of
     * what to do about it.
     *
     * So the save REFUSES. Two tiers claiming the same amount means "Tier 1 or Tier 2?" has two
     * answers, and no arrangement of the rest of the application makes that correct.
     *
     * @return array<string, mixed>|null
     */
    private function tierBandFrom(int $id): ?array
    {
        $key = "tier:{$id}";

        $this->validate([
            "edits.{$key}.budget_min" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            "edits.{$key}.budget_max" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            "edits.{$key}.assignable_by" => ['required', 'in:'.Tier::BY_REQUESTOR.','.Tier::BY_GOVERNANCE],
        ], [
            'edits.'.$key.'.budget_min.numeric' => 'The lower bound must be a number.',
            'edits.'.$key.'.budget_max.numeric' => 'The upper bound must be a number.',
        ], attributes: [
            "edits.{$key}.budget_min" => 'lower bound',
            "edits.{$key}.budget_max" => 'upper bound',
        ]);

        $edit = $this->edits[$key];

        $min = $this->normaliseBound($edit['budget_min'] ?? null);
        $max = $this->normaliseBound($edit['budget_max'] ?? null);

        // An empty string from a cleared input is "unbounded", not zero.
        if ($min === null && $max === null) {
            $min = null;
            $max = null;
        }

        if ($min !== null && $max !== null && bccomp($min, $max, 2) > 0) {
            $this->addError("edits.{$key}.budget_max", 'The upper bound is below the lower bound, so no amount would fall in this tier.');

            return null;
        }

        /*
         * The overlap check, run against the PROPOSED state rather than the saved one.
         *
         * The row being edited is not yet updated, so the check has to see the new band. It is
         * applied by building the candidate tier in memory and comparing it with the others —
         * cheaper and clearer than writing first and rolling back, which would also mean a
         * failed save left an audit row behind.
         *
         * The comparison itself lives in `TierBands`, so the screen and the request-time check
         * cannot hold two versions of it.
         */
        $candidate = new Tier([
            'budget_min' => $min,
            'budget_max' => $max,
            'assignable_by' => $edit['assignable_by'],
            'is_active' => (bool) ($edit['is_active'] ?? true),
        ]);
        $candidate->id = $id;

        foreach (TierBands::collisionsFor($candidate, $id) as $other) {
            $this->addError(
                "edits.{$key}.budget_min",
                self::overlapMessage($candidate, $other)
            );

            return null;
        }

        return [
            'budget_min' => $min,
            'budget_max' => $max,
            'assignable_by' => $edit['assignable_by'],
        ];
    }

    /** '' and null both mean "no bound"; anything else becomes a two-decimal string. */
    private function normaliseBound(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * What to tell an administrator whose band overlaps another.
     *
     * WHY THIS NAMES THE AMOUNT AND THE ORDER TO SAVE IN
     *
     * Moving a boundary takes TWO edits: the lower tier's ceiling goes up, and the upper tier's
     * floor goes up with it. Between those two saves the bands overlap whatever order you choose
     * — so whichever row is saved first is refused, and the administrator is left holding a
     * correct intention that the screen will not accept.
     *
     * "This band overlaps Tier 2" states the fact and stops there. Naming the shared amount, and
     * saying which edit to make first, turns a refusal into a procedure:
     *
     *   1. narrow the upper tier first (its floor moves ABOVE the new boundary), then
     *   2. widen the lower tier (its ceiling moves UP to the new boundary).
     *
     * The reverse order has a moment where both bands contain the new boundary, and there is no
     * arrangement of a single save that avoids it — the check is right to refuse, and the message
     * has to carry the rest.
     *
     * The alternative considered was to allow the overlapping save and warn afterwards. That
     * leaves an invalid configuration live, and the first symptom is a message on somebody else's
     * request form, which is the failure this check exists to prevent.
     */
    public static function overlapMessage(Tier $candidate, Tier $other): string
    {
        $shared = self::sharedAmount($candidate, $other);

        $amount = $shared === null
            ? 'an amount'
            : 'RM'.number_format((float) $shared, 2);

        return "{$amount} would fall into both this tier ({$candidate->bandLabel()}) and "
            ."{$other->name} ({$other->bandLabel()}), so there would be two answers to which tier "
            .'it is. Both bounds are inclusive, so neighbouring bands must not touch: RM50,000 and '
            .'RM50,001, never RM50,000 and RM50,000. '
            .'To move a boundary, save the HIGHER tier first — narrow it, then widen the lower one. '
            .'Between the two saves the bands overlap whichever order you use, so the first save '
            .'must leave no shared amount.';
    }

    /**
     * The lowest amount both bands contain, or null when there is no single value worth naming.
     *
     * For the common case — two tiers touching at a boundary — this is the boundary itself, which
     * is the number the administrator needs to see.
     *
     * The lowest shared amount is the HIGHER of the two floors, because a band contains an amount
     * only from its own floor upwards. A null floor means the band has no lower limit, so the
     * other band's floor is the answer; only when BOTH are unbounded below is there no single
     * value to name, because then every amount at all is in both.
     */
    private static function sharedAmount(Tier $a, Tier $b): ?string
    {
        $floors = array_filter(
            [(string) ($a->budget_min ?? ''), (string) ($b->budget_min ?? '')],
            fn (string $f) => $f !== '',
        );

        if ($floors === []) {
            return null;
        }

        // The highest floor among those the bands actually have.
        usort($floors, fn (string $x, string $y) => bccomp($x, $y, 2));

        return end($floors);
    }

    /**
     * Deactivate or reactivate a row.
     *
     * NOT A DELETE. Every one of these tables is referenced by requests that already
     * exist, and removing a row would either orphan them or cascade through them.
     * Deactivating removes it from the pickers and leaves every existing request intact.
     */
    public function toggleActive(string $kind, int $id): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $model = $this->modelFor($kind);

        if (! $model) {
            return;
        }

        $row = $model::findOrFail($id);

        // The last active row of a kind cannot be deactivated: every request needs one,
        // and an empty picker makes the wizard unusable with no explanation.
        $remaining = $model::where('is_active', true)->where('id', '!=', $id)->count();

        if ($row->is_active && $remaining === 0) {
            $this->addError('toggle', 'This is the last active option. Add another before deactivating it.');

            return;
        }

        $before = $row->getAttributes();

        $row->update(['is_active' => ! $row->is_active]);

        app(AuditService::class)->record('reference_data.toggled', $row, $before, $row->fresh()->getAttributes());

        $this->loadEdits();

        $this->flash = $row->fresh()->is_active
            ? 'Reactivated. It is selectable again.'
            : 'Deactivated. It is no longer selectable, and existing requests are unchanged.';
    }

    public function render()
    {
        return view('livewire.admin.reference-data', [
            'tiers' => Tier::orderBy('sort_order')->get(),
            'classifications' => Classification::orderBy('name')->get(),
            'routes' => GovernanceRoute::orderBy('sort_order')->get(),
            'units' => ReviewUnit::orderBy('sort_order')->get(),
            'kinds' => [
                'tier' => 'Tier',
                'classification' => 'Classification',
                'route' => 'Governance route',
                'unit' => 'Review unit',
            ],
        ])->layout('components.layouts.app', ['title' => 'Reference data']);
    }
}
