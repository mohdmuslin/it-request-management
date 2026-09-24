<?php

namespace App\Livewire\Admin;

use App\Models\Holiday;
use App\Models\StageDueDay;
use App\Models\Tier;
use App\Models\WorkflowStage as WorkflowStageModel;
use App\Services\AuditService;
use App\Services\BusinessCalendar;
use Livewire\Component;

/**
 * Due dates and the holiday calendar.
 *
 * WHY THIS SCREEN MATTERS MORE THAN IT LOOKS
 *
 * The current process has no due dates anywhere, which is why "are we meeting our
 * targets?" cannot be answered today. The targets are configured here, and the
 * holiday calendar is what makes a target in BUSINESS days mean anything — without
 * it every public holiday pushes a due date out by a day nobody accounted for.
 *
 * WHY CHANGING A TARGET IS NOT RETROACTIVE
 *
 * `approval_tasks.due_at` is computed once when a stage is entered and stored. A
 * change here applies to tasks created AFTER it; open tasks keep the target they were
 * given. That is deliberate — moving a target underneath a task set against the old
 * one would make somebody's performance look different depending on when the report
 * was run.
 *
 * The screen says so, because an administrator who expects retroactivity will
 * otherwise conclude the save did not work.
 */
class Settings extends Component
{
    /** Editable targets, keyed "stageId:tierId", with 0 meaning "no tier override". */
    public array $targets = [];

    public string $newHolidayDate = '';

    public string $newHolidayName = '';

    public string $flash = '';

    public function mount(): void
    {
        // Defence in depth: the route is behind the administrator check, and a Livewire
        // action can be invoked directly.
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->loadTargets();
    }

    private function loadTargets(): void
    {
        $this->targets = [];

        foreach (WorkflowStageModel::orderBy('sort_order')->get() as $stage) {
            /*
             * 0 is the sentinel for "no tier override" — the default row.
             *
             * A null key is not usable in a Livewire property array, so the absence of
             * a tier is spelled explicitly rather than left as null.
             */
            $this->targets[$stage->id.':0'] = optional(
                $stage->dueDays()->whereNull('tier_id')->first()
            )->business_days;

            foreach (Tier::orderBy('sort_order')->get() as $tier) {
                $this->targets[$stage->id.':'.$tier->id] = optional(
                    $stage->dueDays()->where('tier_id', $tier->id)->first()
                )->business_days;
            }
        }
    }

    public function saveTargets(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->validate(
            ['targets.*' => ['nullable', 'integer', 'min:0', 'max:365']],
            attributes: ['targets.*' => 'target'],
        );

        $before = StageDueDay::orderBy('id')->get(['workflow_stage_id', 'tier_id', 'business_days'])->toArray();

        foreach ($this->targets as $key => $days) {
            [$stageId, $tierId] = array_map('intval', explode(':', $key));

            $tierId = $tierId === 0 ? null : $tierId;

            $query = StageDueDay::where('workflow_stage_id', $stageId);

            $tierId === null
                ? $query->whereNull('tier_id')
                : $query->where('tier_id', $tierId);

            if ($days === null || $days === '') {
                /*
                 * Clearing a target DELETES the row rather than storing zero.
                 *
                 * Zero is a real target meaning "same day" and `businessDaysFor()`
                 * returns 0 for it — a request due immediately. Deleting means "no
                 * target", which the aging report states honestly rather than inventing
                 * a deadline of today.
                 */
                $query->delete();

                continue;
            }

            StageDueDay::updateOrCreate(
                ['workflow_stage_id' => $stageId, 'tier_id' => $tierId],
                ['business_days' => (int) $days],
            );
        }

        $after = StageDueDay::orderBy('id')->get(['workflow_stage_id', 'tier_id', 'business_days'])->toArray();

        if ($before !== $after) {
            app(AuditService::class)->record(
                event: 'settings.targets_updated',
                subject: auth()->user(),
                old: ['due_days' => $before],
                new: ['due_days' => $after],
            );
        }

        $this->loadTargets();

        $this->flash = 'Targets saved. They apply to tasks created from now on; requests already '
            .'waiting keep the target they were given.';
    }

    public function addHoliday(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $this->validate([
            'newHolidayDate' => ['required', 'date', 'unique:holidays,date'],
            'newHolidayName' => ['required', 'string', 'max:120'],
        ], [
            'newHolidayDate.unique' => 'That date is already in the calendar.',
        ], attributes: [
            'newHolidayDate' => 'date',
            'newHolidayName' => 'holiday name',
        ]);

        $holiday = Holiday::create([
            'date' => $this->newHolidayDate,
            'name' => $this->newHolidayName,
            'source' => 'manual',

            /*
             * Stamped as manually overridden.
             *
             * A calendar sync is written to replace synced rows; without this flag it
             * would delete a holiday somebody entered by hand, and the missing date
             * would only be noticed when a due date landed on it.
             */
            'manually_overridden_at' => now(),
        ]);

        app(AuditService::class)->record('holiday.added', $holiday, null, $holiday->getAttributes());

        // The calendar caches holidays per request. Without this the new date would not
        // affect any due date computed in this same request.
        app(BusinessCalendar::class)->forgetHolidays();

        $this->reset('newHolidayDate', 'newHolidayName');

        $this->flash = 'Holiday added. Due dates from now on will skip it.';
    }

    public function removeHoliday(int $id): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);

        $holiday = Holiday::findOrFail($id);

        app(AuditService::class)->record('holiday.removed', $holiday, $holiday->getAttributes(), null);

        $holiday->delete();

        app(BusinessCalendar::class)->forgetHolidays();

        $this->flash = 'Holiday removed.';
    }

    public function render()
    {
        return view('livewire.admin.settings', [
            'stages' => WorkflowStageModel::orderBy('sort_order')->get(),
            'tiers' => Tier::orderBy('sort_order')->get(),
            'holidays' => Holiday::orderBy('date')->get(),
            'upcoming' => Holiday::whereDate('date', '>=', now()->toDateString())
                ->orderBy('date')->limit(10)->get(),
            'hoursPerDay' => app(BusinessCalendar::class)->hoursPerDay(),
        ])->layout('components.layouts.app', ['title' => 'Due dates and calendar']);
    }
}
