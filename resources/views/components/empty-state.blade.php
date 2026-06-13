@props([
    'icon',
    'heading',
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Leerer Zustand: Icon-Kachel mit Brand-Glow, gestaffelte Entrance-Animation (CSS .empty-state). --}}
<div class="empty-state flex {{ $minHeight }} flex-col items-center justify-center gap-3 px-4 text-center">
    <div class="relative mb-1">
        <span class="absolute -inset-4 rounded-full bg-brand-500/20 blur-2xl" aria-hidden="true"></span>
        <span class="absolute -inset-2.5 rounded-[1.25rem] border border-dashed border-brand-500/30" aria-hidden="true"></span>
        <span class="relative flex size-14 items-center justify-center rounded-tile border border-zinc-200 bg-white text-brand-600 shadow-card dark:border-zinc-800 dark:bg-zinc-900 dark:text-brand-400 dark:shadow-none">
            <flux:icon :name="$icon" class="size-7"/>
        </span>
    </div>
    <flux:heading size="lg">{{ $heading }}</flux:heading>
    {{ $slot }}
</div>
