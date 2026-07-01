{{--
    Empty-State-CTA: setzt den Länderfilter zurück auf „Alle Länder". Beim ersten
    Öffnen ist der Filter der Onboarding-Default (defaultCountry) — dieser Ein-Tap-
    Button holt Nutzer aus einer leeren Region, ohne das Select zu öffnen. Vom
    Aufrufer in @if ($country !== '') gewrappt (nur bei aktivem Filter zeigen).
--}}
<flux:button
    variant="primary"
    size="sm"
    icon="globe-alt"
    wire:click="$set('country', '')"
    x-on:click="$haptic('light')"
    class="cursor-pointer"
>
    {{ __('Alle Länder anzeigen') }}
</flux:button>
