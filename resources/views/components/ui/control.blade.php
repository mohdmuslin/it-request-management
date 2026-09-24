@props(['model', 'name', 'type' => 'select', 'placeholder' => 'Select…', 'options' => []])

{{--
    A shared control style.

    WHY A COMPONENT RATHER THAN CLASSES REPEATED INLINE

    There are roughly forty fields in the wizard; a differing border radius or
    focus ring between them reads as a rendering fault. One definition also means
    the focus style — which is what makes the form usable by keyboard — is fixed in
    one place rather than remembered at each field.
--}}
@php
    $base = 'block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm '
        .'placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 '
        .'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500';

    // Marked invalid if the field has an error, so the problem is visible without
    // reading the message.
    $invalid = $errors->has($model) ? ' border-red-400 focus:border-red-500 focus:ring-red-500' : '';
@endphp

@if ($type === 'select')
    <select id="{{ $model }}" wire:model.live="{{ $model }}"
            class="{{ $base }}{{ $invalid }} @error($model) border-red-400 @enderror">
        @if ($placeholder !== false)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($options as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach

        {{ $slot ?? '' }}
    </select>
@elseif ($type === 'textarea')
    <textarea id="{{ $model }}" wire:model="{{ $model }}" rows="{{ $attributes->get('rows', 4) }}"
              class="{{ $base }}{{ $invalid }}">{{ $slot ?? '' }}</textarea>
@else
    <input id="{{ $model }}" type="{{ $type }}" wire:model="{{ $model }}"
           class="{{ $base }}{{ $invalid }}"
           {{ $attributes->except(['rows']) }}>
@endif
