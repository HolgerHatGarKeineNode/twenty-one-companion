@props([
    'variant' => 'list',
    'count' => 3,
])

{{-- Skeleton-Loader (Phase 1.4): ruhiger Platzhalter statt nacktem Spinner,
     spiegelt die Form der echten Cards. Per `wire:loading` einblenden.
     variant: 'list' (Listen-Card mit Avatar) | 'detail' (Detail-Section). --}}
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} aria-hidden="true">
    @if ($variant === 'detail')
        <div class="rounded-card border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center gap-4">
                <div class="skeleton size-12 rounded-full"></div>
                <div class="flex flex-1 flex-col gap-2">
                    <div class="skeleton h-4 w-1/2 rounded"></div>
                    <div class="skeleton h-3 w-1/3 rounded"></div>
                </div>
            </div>
            <div class="mt-5 flex flex-col gap-2.5">
                <div class="skeleton h-3 w-full rounded"></div>
                <div class="skeleton h-3 w-5/6 rounded"></div>
                <div class="skeleton h-3 w-2/3 rounded"></div>
            </div>
        </div>
    @else
        @for ($i = 0; $i < (int) $count; $i++)
            <div class="flex items-center gap-4 rounded-card border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="skeleton size-10 shrink-0 rounded-full"></div>
                <div class="flex flex-1 flex-col gap-2">
                    <div class="skeleton h-3.5 w-2/5 rounded"></div>
                    <div class="skeleton h-3 w-3/5 rounded"></div>
                </div>
            </div>
        @endfor
    @endif
</div>
