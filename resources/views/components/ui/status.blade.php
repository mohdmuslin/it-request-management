@props(['status'])

{{--
    A status pill.

    WHY THE COLOURS ARE GROUPED BY MEANING, NOT BY STATUS

    Fourteen statuses each with a colour would be a legend nobody can learn. What a
    reader needs at a glance is one of five things: nothing has happened yet, it is
    with somebody, it has been returned to me, it is decided, or it is finished.
    The label carries the detail; the colour carries the category.
--}}
@php
    use App\Enums\RequestStatus;

    $tone = match ($status) {
        RequestStatus::Draft => 'bg-slate-100 text-slate-700 ring-slate-200',

        // Waiting on somebody else — the ordinary, healthy state for an open request.
        RequestStatus::Submitted,
        RequestStatus::PendingProjectOwner,
        RequestStatus::PendingProjectSponsor,
        RequestStatus::PendingCompletenessReview,
        RequestStatus::PendingTechnicalRecommendation,
        RequestStatus::PendingConsolidation,
        RequestStatus::PendingCommitteeDecision => 'bg-blue-50 text-blue-800 ring-blue-200',

        // Needs the requestor's attention. Amber rather than red: it is a task, not
        // a failure, and red on an open request teaches people to ignore red.
        RequestStatus::ReturnedForAmendment => 'bg-amber-50 text-amber-900 ring-amber-200',

        RequestStatus::Approved,
        RequestStatus::ApprovedWithConditions => 'bg-green-50 text-green-800 ring-green-200',

        RequestStatus::NotRecommended,
        RequestStatus::Withdrawn => 'bg-slate-100 text-slate-600 ring-slate-200',

        RequestStatus::Closed => 'bg-slate-800 text-white ring-slate-800',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset $tone"]) }}>
    {{ $status->label() }}
</span>
