<?php

namespace App\Livewire\Admin;

use App\Models\Classification;
use App\Models\GovernanceRoute;
use App\Models\ReviewUnit;
use App\Models\Tier;
use App\Services\AuditService;
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
            $this->edits['tier:'.$row->id] = ['name' => $row->name, 'is_active' => (bool) $row->is_active];
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

        $row->update($updates);

        app(AuditService::class)->record('reference_data.updated', $row, $before, $row->fresh()->getAttributes());

        $this->loadEdits();

        $this->flash = 'Saved.';
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
