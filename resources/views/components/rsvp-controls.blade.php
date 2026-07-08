@props([
    'status' => null,
    'attendees' => 0,
    'mightAttendees' => 0,
    'canRsvp' => false,
    // Ist die Anmeldung für dieses Meetup aktiviert? Aus => keine Buttons.
    'rsvpEnabled' => true,
])

@php($showButtons = $canRsvp && $rsvpEnabled)

{{-- RSVP für einen Meetup-Termin (B.5): Zählerzeile plus „Ich komme" /
     „Vielleicht" / „Kann nicht". Wird auf der Meetup-Detailseite (nächster
     Termin) und im Termin-Slide-In genutzt. Die Buttons rufen `setRsvp(...)`
     der umgebenden Livewire-Komponente auf (geliefert vom Trait
     {@see \App\Livewire\Concerns\InteractsWithEventRsvp}); ohne `canRsvp`
     (kein Token / keine Termin-ID) bleibt nur die Zählerzeile. --}}
@if ($attendees > 0 || $mightAttendees > 0 || $showButtons)
    <div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }}>
        @if ($attendees > 0 || $mightAttendees > 0)
            <flux:text class="text-sm tabular-nums">
                {{ __(':yes Zusagen · :maybe Vielleicht', ['yes' => $attendees, 'maybe' => $mightAttendees]) }}
            </flux:text>
        @endif

        @if ($showButtons)
            <div class="flex flex-wrap gap-2">
                <flux:button
                    wire:click="setRsvp('attending')"
                    x-on:click="$haptic('medium')"
                    wire:loading.attr="disabled"
                    wire:target="setRsvp"
                    size="sm"
                    icon="check"
                    :variant="$status === 'attending' ? 'primary' : 'outline'"
                    class="cursor-pointer"
                >
                    {{ __('Ich komme') }}
                </flux:button>
                <flux:button
                    wire:click="setRsvp('maybe')"
                    x-on:click="$haptic('medium')"
                    wire:loading.attr="disabled"
                    wire:target="setRsvp"
                    size="sm"
                    icon="question-mark-circle"
                    :variant="$status === 'maybe' ? 'primary' : 'outline'"
                    class="cursor-pointer"
                >
                    {{ __('Vielleicht') }}
                </flux:button>
                @if ($status === 'attending' || $status === 'maybe')
                    <flux:button
                        wire:click="setRsvp('none')"
                        x-on:click="$haptic('light')"
                        wire:loading.attr="disabled"
                        wire:target="setRsvp"
                        size="sm"
                        variant="ghost"
                        icon="x-mark"
                        class="cursor-pointer"
                    >
                        {{ __('Kann nicht') }}
                    </flux:button>
                @endif
            </div>
        @endif
    </div>
@endif
