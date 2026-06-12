@props([
    'icon',
    'heading',
    'minHeight' => 'min-h-[40dvh]',
])

<div class="flex {{ $minHeight }} flex-col items-center justify-center gap-3 text-center">
    <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
        <flux:icon :name="$icon" class="size-7"/>
    </span>
    <flux:heading size="lg">{{ $heading }}</flux:heading>
    {{ $slot }}
</div>
