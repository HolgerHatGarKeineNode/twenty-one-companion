@props([
    'logo' => null,
    'name',
    'size' => 'lg',
])

@if ($logo)
    <flux:avatar :src="$logo" :size="$size" class="shrink-0"/>
@else
    <flux:avatar :name="$name" :size="$size" class="shrink-0"/>
@endif
