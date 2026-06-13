@props([
    'logo' => null,
    'name',
    'size' => 'lg',
])

{{-- Meetup-Avatar mit dezentem Ring für Tiefe (Phase 1.6). --}}
@php($ring = 'shrink-0 ring-1 ring-black/5 dark:ring-white/10')
@if ($logo)
    <flux:avatar :src="$logo" :size="$size" :class="$ring"/>
@else
    <flux:avatar :name="$name" :size="$size" :class="$ring"/>
@endif
