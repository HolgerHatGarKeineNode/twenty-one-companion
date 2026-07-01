@props([
    'link' => null,       // Termin-Link (optional) → „Link öffnen"
    'share',              // Name der Livewire-Methode fürs Teilen ("share" | "shareEvent")
    'meetupRoute' => null, // Ziel für „Zum Meetup" (optional; auf der Detailseite null)
])

@php
    // Der letzte Button füllt die Reihe, wenn die Button-Anzahl ungerade ist
    // (Link und „Zum Meetup" sind optional → 2–4 Buttons). So bleibt das 2-Spalten-
    // Raster ohne Lücke, egal welche optionalen Aktionen vorhanden sind.
    $count = 2 + ($link ? 1 : 0) + ($meetupRoute ? 1 : 0);
    $fillLastRow = $count % 2 === 1;
@endphp

{{--
    Einheitliches Aktions-Grid für einen Termin: 2-Spalten-Raster gleichwertiger
    Outline-Pills, die unter dem RSVP-Block zurücktreten. Genutzt vom Termin-
    Slide-In (mit „Zum Meetup") und der Meetup-Detailseite (ohne).
--}}
<div {{ $attributes->class('grid grid-cols-2 gap-2') }}>
    @if ($link)
        <flux:button
            wire:click="openLink({{ Js::from($link) }})"
            x-on:click="$haptic('light')"
            variant="outline"
            size="sm"
            icon="link"
            class="pressable w-full cursor-pointer"
        >
            {{ __('Link öffnen') }}
        </flux:button>
    @endif

    <flux:button
        wire:click="{{ $share }}"
        x-on:click="$haptic('light')"
        variant="outline"
        size="sm"
        icon="share"
        class="pressable w-full cursor-pointer"
    >
        {{ __('Teilen') }}
    </flux:button>

    <flux:button
        wire:click="addToCalendar"
        x-on:click="$haptic('light')"
        variant="outline"
        size="sm"
        icon="calendar-days"
        @class(['pressable w-full cursor-pointer', 'col-span-2' => $fillLastRow && ! $meetupRoute])
    >
        {{ __('Zum Kalender') }}
    </flux:button>

    @if ($meetupRoute)
        <flux:button
            :href="$meetupRoute"
            wire:navigate
            x-on:click="$haptic('light')"
            variant="outline"
            size="sm"
            icon="map-pin"
            @class(['pressable w-full cursor-pointer', 'col-span-2' => $fillLastRow])
        >
            {{ __('Zum Meetup') }}
        </flux:button>
    @endif
</div>
