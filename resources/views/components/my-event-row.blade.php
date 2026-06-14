@props([
    'event',
    'past' => false,
])

{{--
    Zeile in der Termin-Verwaltung des Meetup-Details (Phase 5.3): Datum/Zeit,
    optionaler Ort und Inline-Edit-Affordance. `past` dämpft vergangene Termine
    (graues Icon, reduzierte Deckkraft).
--}}
<div {{ $attributes->class([
    'flex items-center gap-3 rounded-tile border border-zinc-200 p-3 dark:border-zinc-800',
    'opacity-70' => $past,
]) }}>
    <span @class([
        'flex size-10 shrink-0 items-center justify-center rounded-xl',
        'bg-brand-500/15 text-brand-600 dark:text-brand-400' => ! $past,
        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $past,
    ])>
        <flux:icon name="calendar-days" class="size-5"/>
    </span>
    <div class="min-w-0 flex-1">
        <span class="font-semibold">{{ $event->start->forDisplay()->translatedFormat('D, d. M Y · H:i') }}</span>
        @if ($event->location)
            <flux:text class="truncate text-sm">{{ $event->location }}</flux:text>
        @endif
    </div>
    <flux:button
        type="button"
        variant="ghost"
        size="sm"
        icon="pencil-square"
        :aria-label="__('Termin bearbeiten')"
        x-on:click="$haptic('light'); $flux.modal('create-event').show(); Livewire.dispatch('open-event-editor', {{ Js::from(['eventId' => $event->id]) }})"
        class="shrink-0 cursor-pointer"
    />
</div>
