@props([
    'route',
    'icon',
    'label',
    'match' => null,
])

@php
    $active = request()->routeIs(...explode(',', $match ?? $route));
@endphp

<a
    href="{{ route($route) }}"
    wire:navigate
    x-on:click="$haptic('light')"
    @if($active) aria-current="page" @endif
    {{ $attributes->class([
        'pressable flex flex-col items-center justify-center gap-1 py-2.5',
        'text-accent' => $active,
        'text-zinc-500 active:text-zinc-700 dark:text-zinc-400 dark:active:text-zinc-200' => ! $active,
    ]) }}
>
    <flux:icon :name="$icon" :variant="$active ? 'solid' : 'outline'" class="size-6"/>
    <span class="text-[11px] font-semibold leading-none">{{ $label }}</span>
</a>
