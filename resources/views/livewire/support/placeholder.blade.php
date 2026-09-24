<div>
    <h1 class="text-xl font-semibold text-slate-900">{{ $title }}</h1>

    <div class="mt-5 max-w-2xl rounded-xl border border-dashed border-slate-300 bg-white p-8">
        <p class="text-sm font-medium text-slate-700">Not built yet</p>

        @if ($summary)
            <p class="mt-2 text-sm text-slate-500">{{ $summary }}</p>
        @endif

        @if ($phase)
            <p class="mt-4 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                Scheduled for {{ $phase }}
            </p>
        @endif

        <p class="mt-4 text-xs text-slate-500">
            This route exists so the navigation, layout and role filtering can be verified
            before the screen behind it is written. It is planned work, not a fault.
        </p>
    </div>
</div>
