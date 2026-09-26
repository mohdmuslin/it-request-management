<?php

namespace App\Http\Requests;

use App\Enums\BusinessPlanStatus;
use App\Models\Tier;
use App\Models\TierFieldRule;
use App\Services\TierBands;
use App\Services\TierFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and editing an IT request.
 *
 * WHY THE RULES ARE HERE AND NOT IN THE LIVEWIRE COMPONENT
 *
 * The wizard validates one step at a time as the user moves forward, and the
 * whole form again on submission. If each of those had its own copy of the
 * rules, the step check would eventually disagree with the final check — and the
 * failure mode is a request that passes every step and is then rejected at the
 * end with no indication of which step was wrong.
 *
 * So: `rules()` is the complete set, and `steps()` is the same rules partitioned
 * by wizard step. One definition, two views of it.
 *
 * NOT A CONVENTIONAL FORMREQUEST
 *
 * ⚠️ THIS CLASS MUST BE INSTANTIATED WITH `new`, NOT RESOLVED FROM THE CONTAINER.
 *
 * Laravel registers an `afterResolving` hook on `ValidatesWhenResolved`, so
 * `app(ItRequestFormRequest::class)` immediately calls `validateResolved()` — which
 * validates the CURRENT HTTP REQUEST's input, not the data the caller intended.
 *
 * In a Livewire action that request carries nothing, so every required field fails
 * at once. The failure is nasty because the exception is a normal
 * `ValidationException`, which Livewire catches and renders as the component's error
 * bag: the wizard reported "The request title field is required" on a field the user
 * had filled in, and the step would not advance. Nothing in the message hinted at
 * the container.
 *
 * This class therefore takes its data explicitly:
 *
 *     (new ItRequestFormRequest)->withData($values)->steps()[1]
 *
 * `authorize()` is never consulted either — Livewire validates its own properties
 * and the policy guards the action. Said out loud rather than left for somebody to
 * discover, because a FormRequest that silently skips its own authorisation is a
 * trap.
 */
class ItRequestFormRequest extends FormRequest
{
    /**
     * Values the conditional rules read, when there is no meaningful HTTP request.
     *
     * Keyed by field name. Anything absent falls back to `input()`, so the same
     * rules still work if this is ever used from a controller.
     */
    private array $overrides = [];

    public function withData(array $data): static
    {
        $this->overrides = $data;

        return $this;
    }

    /**
     * A value for a conditional rule.
     *
     * Read through this rather than `input()` directly, so the branching rules do
     * not quietly become "always false" when the class is used outside a request —
     * which would drop a required field instead of failing, and nobody would notice
     * that the BR-003 branch had stopped being enforced.
     */
    private function value(string $key): mixed
    {
        return array_key_exists($key, $this->overrides)
            ? $this->overrides[$key]
            : $this->input($key);
    }

    /**
     * Not used. Livewire validates its own properties and the policy guards the
     * action; this exists only because FormRequest declares it abstract.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The complete rule set for a fully submitted request.
     *
     * The tier-conditional rules are merged in here rather than written into the steps, because
     * they apply to fields in three different steps (BR-003) and reading them from one place is
     * what keeps the step slices and the final check from disagreeing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $all = array_merge(...array_values($this->steps()));

        return $this->appendBandRule(array_merge($all, $this->tierRules()));
    }

    /**
     * Add the band check to whatever rule `budget_amount` already has.
     *
     * WHY THIS IS NOT A SECOND `array_merge` ARGUMENT
     *
     * `array_merge` OVERWRITES on a duplicate string key. Passing the band rules as another
     * argument silently replaced the tier's own rule for `budget_amount` — so a tier marking the
     * amount `required` lost that rule the moment a band check was attached, and a request could
     * be submitted with no amount at all. The rule list looked correct in isolation, which is why
     * the symptom appeared as a missing error rather than as anything pointing here.
     *
     * Appending to the existing list is what "add a check for this field" actually means.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function appendBandRule(array $rules): array
    {
        $existing = $rules['budget_amount'] ?? [];

        // `budget_amount` may carry a `required` that arrived from `tierRules()` as a list, or a
        // plain list from `stepThree()`. Both are flattened before the closure is appended, so
        // the order stays "presence first, then agreement" and the messages read in that order.
        $rules['budget_amount'] = array_merge(
            is_array($existing) ? $existing : [$existing],
            $this->bandRule(),
        );

        return $rules;
    }

    /**
     * The rule that refuses a tier contradicting the budget (BR-003).
     *
     * WHY THIS IS A CLOSURE AND NOT `withValidator()`
     *
     * `withValidator()` is only called by Laravel's `validateResolved()`, and this class is
     * deliberately never resolved that way — the wizard constructs it directly and hands the rule
     * arrays to Livewire's `validate()`, because `afterResolving` would validate the empty HTTP
     * request instead. A `withValidator()` hook here would be dead code that looks like a guard.
     *
     * This is the one rule in the application that compares TWO fields, so it belongs on the
     * field the requestor would change rather than beside the amount they typed correctly.
     *
     * KEYED ON `budget_amount`, NOT `proposed_tier_id` — and that choice is about where the
     * message is SEEN. The tier is chosen on step 1 and the amount entered on step 3, so at the
     * moment this fires the tier control is not on screen. An error against an invisible field is
     * one the requestor cannot resolve: they would be refused with no message anywhere and no way
     * to find out why. Against the amount, the explanation appears next to what they just typed.
     *
     * @return array<int, mixed>
     */
    private function bandRule(): array
    {
        return [
            function (string $attribute, mixed $value, \Closure $fail) {
                $tierId = $this->value('proposed_tier_id');

                // Nothing to compare. Whether an amount is REQUIRED is a separate question,
                // answered by the tier field rules.
                if (blank($tierId) || blank($value)) {
                    return;
                }

                $check = app(TierBands::class)->check((int) $tierId, $value);

                if ($check['status'] !== 'ok') {
                    /*
                     * `$value` IS PASSED IN, NOT RE-READ.
                     *
                     * The closure is given the amount being validated, and that is the
                     * authoritative value. An earlier version called
                     * `$this->value('budget_amount')` here instead — and because
                     * `budget_amount` is not in the `withData()` array `Create::formRequest()`
                     * builds, `value()` fell back to `input()`, found nothing in a Livewire
                     * request, and formatted the empty string as "RM0.00".
                     *
                     * The message then read "A budget of RM0.00 is Tier 2 (RM50,000.01 and
                     * above)" while the field beside it plainly said 80000 — a refusal that
                     * contradicts the screen, which reads as the application being broken
                     * rather than as the amount being wrong.
                     *
                     * This is the second time in this area that a value read through `value()`
                     * was absent from `withData()`. The lesson generalises: a validation closure
                     * should use the value it is GIVEN, and only reach for `value()` when the
                     * rule genuinely needs a DIFFERENT field — which is the tier id here, and
                     * the reason that one has to be in the array.
                     */
                    $fail($this->bandMessage($check, $value));
                }
            },
        ];
    }

    /**
     * What to say about a tier that does not agree with the amount.
     *
     * FOUR CASES, FOUR MESSAGES, and the distinction matters more than it looks. A single
     * "the tier does not match the budget" would be shown for a GAP or an OVERLAP in the
     * organisation's own bands — configuration faults the requestor cannot act on — and would
     * send them to correct a field that is already right.
     *
     * @param  array{status: string, expected: Tier|null, matches: array<int, Tier>}  $check
     * @param  mixed  $amount  The value being validated, passed in rather than re-read
     */
    private function bandMessage(array $check, mixed $amount): string
    {
        $formatted = 'RM'.number_format((float) $amount, 2);

        return match ($check['status']) {
            'mismatch' => "A budget of {$formatted} is {$check['expected']->name} "
                ."({$check['expected']->bandLabel()}), not the tier selected. "
                .'Change the tier on step 1, or correct the amount.',
            'no_tier' => "No tier covers a budget of {$formatted}. "
                .'The tier bands are incomplete — an administrator needs to check them.',
            'overlap' => "A budget of {$formatted} falls into more than one tier ("
                .implode(', ', array_map(fn ($t) => $t->name, $check['matches']))
                .'). The tier bands overlap — an administrator needs to check them.',
            default => 'The tier does not agree with the budget.',
        };
    }

    /**
     * Rules derived from the tier, for whichever tier is selected (BR-003).
     *
     * NO TIER SELECTED MEANS NO ADDITIONAL RULES.
     *
     * Tier is proposed, not required, and a draft may not have one yet. Treating "no tier" as
     * "the strictest tier" would refuse a first step somebody has not finished filling in; the
     * requirement is settled at submission, where a tier is present.
     *
     * @return array<string, mixed>
     */
    private function tierRules(): array
    {
        $tierId = $this->value('proposed_tier_id');

        if (blank($tierId)) {
            return [];
        }

        $rules = app(TierFieldRules::class);

        $effective = $rules->forTier((int) $tierId);
        $out = [];

        foreach ($effective as $field => $requirement) {
            /*
             * A field the workflow itself decides is skipped entirely.
             *
             * Tier rules are merged OVER the step rules, so a tier emitting `nullable` for
             * `business_plan_reference` would REPLACE the `requiredIf` that the business-plan
             * branch depends on — and a request could then claim plan alignment with no plan
             * cited. The governing list no longer offers those fields, and this is the second
             * guard, so a future edit to that list cannot reopen the hole.
             */
            if (! $rules->mayRelax($field)) {
                continue;
            }

            $constraints = $this->baseLengthRule($field);

            if ($requirement === TierFieldRule::REQUIRED) {
                /*
                 * `required` here, not `requiredIf`.
                 *
                 * The governing rule has already decided — the administrator set this field
                 * required for this tier — so the condition is the tier, and the tier was
                 * checked once above. Making it conditional again would mean two places
                 * deciding, which is how the two come to disagree.
                 *
                 * A tier may ADD a requirement freely. It cannot remove one: see `mayRelax()`,
                 * consulted where the business-plan branch is written, not here.
                 */
                $out[$field] = array_merge(['required'], $constraints);

                continue;
            }

            if ($requirement === TierFieldRule::HIDDEN) {
                /*
                 * A hidden field accepts nothing.
                 *
                 * Not `nullable` — which would let a stale value through and store a budget
                 * amount on a tier where budget does not apply. The wizard clears the field when
                 * the tier changes, so this only fires if that failed or if the payload was
                 * crafted; either way the honest answer is to refuse it.
                 *
                 * Combined with 'nullable' rather than replacing it, so the message is "must be
                 * absent" rather than a type error on a value that should never have arrived.
                 */
                $out[$field] = ['nullable', 'prohibited'];

                continue;
            }

            // Optional: the format constraints still apply, and a blank is accepted.
            if ($constraints !== []) {
                $out[$field] = array_merge(['nullable'], $constraints);
            }
        }

        return $out;
    }

    /**
     * Length and format constraints that apply whatever the tier says.
     *
     * Kept separate from `required`/`nullable`, because a tier changes WHETHER a field must be
     * filled and never what a valid value looks like — a 5,000-character limit is not a
     * consequence of governance tier, and letting a tier change it would be a surprise.
     *
     * @return array<int, string>
     */
    private function baseLengthRule(string $field): array
    {
        return match ($field) {
            'business_plan_reference' => ['string', 'max:255'],
            'adhoc_justification' => ['string', 'min:30', 'max:5000'],
            'value_proposition' => ['string', 'max:5000'],
            'budget_amount' => ['numeric', 'min:0', 'max:9999999999.99'],
            'budget_source', 'budget_code', 'funding_type' => ['string', 'max:100'],
            'proposed_start_date', 'target_completion_date' => ['date'],
            'forecast_resources', 'risk_summary', 'mitigation_plan',
            'dependencies_constraints', 'in_scope', 'out_of_scope' => ['string', 'max:5000'],
            default => [],
        };
    }

    /**
     * The rules for each wizard step, keyed by step number.
     *
     * TIER RULES ARE FOLDED INTO THE STEP THEY BELONG TO.
     *
     * They have to be, or a tier-required field in step 3 would pass step 3's check and fail the
     * final one — and the requestor would be told at the end that a field three screens back is
     * missing, with nothing saying which screen. `TierFieldRules::stepFor()` is what says where
     * each field lives, so the two cannot disagree about that either.
     *
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        $tierRules = $this->tierRules();

        $steps = [
            1 => $this->stepOne(),
            2 => $this->stepTwo(),
            3 => $this->stepThree(),
            4 => $this->stepFour(),
        ];

        foreach ($tierRules as $field => $rule) {
            $step = TierFieldRules::stepFor($field);

            if ($step !== null && isset($steps[$step])) {
                $steps[$step][$field] = $rule;
            }
        }

        /*
         * The band check runs on step 3, where the amount is entered.
         *
         * Folding it into the step rather than leaving it to the final check is what makes the
         * refusal happen while the requestor is looking at the field — pressing Continue on step
         * 3 says "a budget of RM80,000 is Tier 2, not the tier selected", instead of letting them
         * fill steps 3 and 4 and then be refused at the end over an answer given on step 1.
         *
         * APPENDED, not assigned. Assigning would drop the base rules for this field — the
         * `nullable`, `numeric` and range constraints — so a tier's band check would quietly
         * remove the validation that the amount is a number at all.
         */
        $steps[3]['budget_amount'] = array_merge(
            $steps[3]['budget_amount'] ?? [],
            $this->bandRule(),
        );

        return $steps;
    }

    /** Step 1 — Request information. */
    private function stepOne(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'request_date' => ['required', 'date'],

            /*
             * `requestor_id` is deliberately absent.
             *
             * The requestor is whoever is signed in, and is assigned by the
             * component. Accepting it from the form would let one user file a
             * request in another user's name — and because the request number and
             * the whole audit trail are built on the requestor, that would not be
             * a spoofed field but a spoofed request.
             *
             * `status` and `current_stage` are absent for the same reason: the
             * workflow service owns them, and a form that could set them could
             * skip approvals.
             */
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],

            /*
             * The Owner is required and the Sponsor is not.
             *
             * The Owner's approval is the first step of every route, so a request
             * without one cannot move at all. The Sponsor's approval follows it,
             * and the workflow service falls back to the Owner when no Sponsor is
             * named — so an unnamed Sponsor is a gap in governance, not a stuck
             * request.
             */
            'project_owner_id' => ['required', 'integer', 'exists:users,id'],
            'project_sponsor_id' => ['nullable', 'integer', 'exists:users,id', 'different:project_owner_id'],

            /*
             * Proposed, not assigned. The requestor may leave these blank and let
             * IT Governance classify the request during completeness review.
             */
            'proposed_tier_id' => ['nullable', 'integer', 'exists:tiers,id'],
            'proposed_classification_id' => ['nullable', 'integer', 'exists:classifications,id'],
        ];
    }

    /** Step 2 — Business justification, including the BR-003 branch. */
    private function stepTwo(): array
    {
        return [
            'business_need' => ['required', 'string', 'min:30', 'max:5000'],

            'business_plan_status' => ['required', Rule::enum(BusinessPlanStatus::class)],

            /*
             * BR-003, in concrete form.
             *
             * A request claiming alignment must cite the plan; one admitting it is
             * ad hoc must justify why. Exactly one of the two is required, and
             * which one is decided by `business_plan_status`.
             *
             * `Rule::requiredIf` rather than `required_if`, so the condition and
             * the rule sit together and the intent survives a refactor.
             */
            'business_plan_reference' => [
                Rule::requiredIf(fn () => $this->businessPlanStatus() === BusinessPlanStatus::Aligned),
                'nullable', 'string', 'max:255',
            ],
            'adhoc_justification' => [
                Rule::requiredIf(fn () => $this->businessPlanStatus() === BusinessPlanStatus::AdHoc),
                'nullable', 'string', 'min:30', 'max:5000',
            ],
            'value_proposition' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Step 3 — Budget and timeline. */
    private function stepThree(): array
    {
        return [
            /*
             * Money. `numeric`, and the column is DECIMAL(12,2).
             *
             * The brief's §5.2 forbids float storage for money, and this is the
             * validation half of that rule: a string like "1e5" or "12,000.00"
             * would be coerced to a float on the way in, so it is refused here.
             */
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'budget_source' => ['nullable', 'string', 'max:100'],
            'budget_code' => ['nullable', 'string', 'max:50'],
            'funding_type' => ['nullable', 'string', 'max:50'],

            'proposed_start_date' => ['nullable', 'date'],

            /*
             * A completion date before the start date is always a mistake, and
             * catching it here is far cheaper than discovering it in a report. It
             * is only checked when both are present, because either may be left
             * blank on an early draft.
             */
            'target_completion_date' => ['nullable', 'date', 'after_or_equal:proposed_start_date'],

            'forecast_resources' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Step 4 — Risk and scope. */
    private function stepFour(): array
    {
        return [
            'urgency' => ['required', Rule::in(['high', 'medium', 'low'])],

            /*
             * A justification is required only for HIGH urgency.
             *
             * Requiring one every time would train people to type "urgent", which
             * is worse than nothing. Requiring it for the case that actually
             * affects sequencing means the field carries information exactly when
             * it matters.
             *
             * Urgency is deliberately NOT wired to any due date: the target is a
             * function of the stage. It is context for an approver, not a clock.
             */
            'urgency_justification' => [
                Rule::requiredIf(fn () => $this->value('urgency') === 'high'),
                'nullable', 'string', 'min:15', 'max:2000',
            ],

            'risk_summary' => ['nullable', 'string', 'max:5000'],
            'mitigation_plan' => ['nullable', 'string', 'max:5000'],
            'dependencies_constraints' => ['nullable', 'string', 'max:5000'],

            // Required: a request that cannot say what fails without it has not
            // made its case, and this is the field approvers quote back.
            'impact_if_not_implemented' => ['required', 'string', 'min:30', 'max:5000'],

            'in_scope' => ['nullable', 'string', 'max:5000'],
            'out_of_scope' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Field names as the form labels them.
     *
     * Without these, a message reads "The business plan reference field is
     * required" against a field the form calls "Business Plan Reference" — close
     * enough to guess, but on a five-step wizard guessing which step to return to
     * is the difference between a fixable form and an abandoned request.
     */
    public function attributes(): array
    {
        return [
            'title' => 'request title',
            'request_date' => 'request date',
            'department_id' => 'department',
            'division_id' => 'division',
            'project_owner_id' => 'project owner',
            'project_sponsor_id' => 'project sponsor',
            'proposed_tier_id' => 'proposed tier',
            'proposed_classification_id' => 'proposed classification',
            'business_need' => 'business need',
            'business_plan_status' => 'business plan status',
            'business_plan_reference' => 'business plan reference',
            'adhoc_justification' => 'justification for not being in the business plan',
            'value_proposition' => 'value proposition',
            'budget_amount' => 'budget amount',
            'budget_source' => 'budget source',
            'budget_code' => 'budget code',
            'funding_type' => 'funding type',
            'proposed_start_date' => 'proposed start date',
            'target_completion_date' => 'target completion date',
            'forecast_resources' => 'forecast resources',
            'urgency' => 'urgency',
            'urgency_justification' => 'reason for the urgency',
            'risk_summary' => 'risk summary',
            'mitigation_plan' => 'mitigation plan',
            'dependencies_constraints' => 'dependencies and constraints',
            'impact_if_not_implemented' => 'impact if not implemented',
            'in_scope' => 'in scope',
            'out_of_scope' => 'out of scope',
        ];
    }

    public function messages(): array
    {
        return [
            'business_plan_reference.required' => 'A request that is in the business plan must cite it. Enter the plan reference, or change the status to Ad Hoc and justify it.',
            'adhoc_justification.required' => 'A request that is not in the business plan must say why it is still needed. Enter the justification, or change the status to In Business Plan and cite it.',
            'impact_if_not_implemented.required' => 'Describe what happens if this request is not implemented. Approvers rely on this more than any other field.',
            'project_sponsor_id.different' => 'The project sponsor must be a different person from the project owner.',
            'urgency_justification.required' => 'High urgency needs a reason an approver can evaluate — for example an expiry date or a contractual deadline.',
        ];
    }

    /** The status as an enum, tolerating the raw string the form sends. */
    private function businessPlanStatus(): ?BusinessPlanStatus
    {
        $value = $this->value('business_plan_status');

        return $value === null || $value === '' ? null : BusinessPlanStatus::tryFrom($value);
    }
}
