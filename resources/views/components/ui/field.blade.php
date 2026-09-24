@props([
    'name',
    'label',
    'hint' => null,
    'required' => false,
])

{{--
    One field's label, hint, control and error message.

    WHY THIS EXISTS

    The wizard has around forty fields. Written inline, the label markup, the
    required marker and the error block are copied forty times, and the first time
    somebody adds a field without the error block it fails silently — the value is
    rejected and nothing on screen says why.

    The error is rendered by name rather than by a boolean prop, so it cannot get
    out of step with the field it belongs to.
--}}
<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
        {{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
    </label>

    @if ($hint)
        <p class="mt-0.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    <div class="mt-1.5">
        {{ $slot }}
    </div>

    @error($name)
        {{-- role="alert" so a screen reader announces it when it appears. --}}
        <p class="mt-1 text-xs font-medium text-red-700" role="alert">{{ $message }}</p>
    @enderror
</div>
