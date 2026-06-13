{{-- Verzeichnis-Karte für Städte/Orte: Flagge, Name, optionaler Untertitel
     (nicht klickbar). AAA-Niveau (Phase 1.6): weiche Elevation. --}}
@props([
    'flag' => null,
    'name',
    'subtitle' => null,
])

<div {{ $attributes->class('surface-card flex items-center gap-4 p-4') }}>
    @if (is_string($flag))
        <img src="{{ $flag }}" alt="" class="h-4 w-6 shrink-0 rounded-[2px] object-cover ring-1 ring-black/5"/>
    @endif
    <span class="flex min-w-0 flex-col gap-0.5">
        <span class="truncate font-semibold">{{ $name }}</span>
        @if ($subtitle)
            <flux:text class="truncate text-sm">{{ $subtitle }}</flux:text>
        @endif
    </span>
</div>
