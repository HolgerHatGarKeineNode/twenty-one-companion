{{-- Übersteuert `flux/input/clearable.blade.php` aus dem Paket, aus genau einem
     Grund: das aria-label.

     Der Original-Stub beginnt mit `@blaze(fold: true, memo: true)`. Blaze backt
     das ERGEBNIS von `__()` beim Kompilieren in die View ein — der Text ändert
     sich danach nicht mehr mit der Sprache. Gemessen am 2026-08-16: nach einem
     Render in `de` liefert derselbe Aufruf unter `en` weiter den deutschen Text.

     Diese Kopie lässt `@blaze` weg — das reicht hier aber NICHT für echte
     Pro-Request-Auflösung, und das ist ebenfalls gemessen: `flux:input` selbst
     ist gefaltet und backt diesen Knopf beim Kompilieren mit ein.

     Was die Datei also wirklich bewirkt: sie ändert, WELCHE Sprache einfriert —
     statt „Clear input" für alle steht dort der Text der Sprache, in der die
     Views kompiliert wurden. Für eine App, deren Standard `de` ist und deren
     Quell-Strings deutsch sind, ist das die bessere Vorgabe. Vollständig lösbar
     wäre es nur ohne Flux' `clearable` (eigener Knopf) — für ein aria-label an
     drei Suchfeldern nicht den Umbau wert.

     Anders als hier ist der Schließen-Knopf der Sheets ECHT pro Request
     übersetzt: der sitzt in `components/sheet.blade.php`, also in unserem
     eigenen, ungefalteten Template.

     Ansonsten Zeile für Zeile der Stub aus livewire/flux; bei einem Flux-Update
     gegen `vendor/livewire/flux/stubs/resources/views/flux/input/clearable.blade.php`
     abgleichen. --}}

@props([
    'iconVariant' => 'mini',
    'size' => null,
])

@php
$attributes = $attributes->merge([
    'variant' => 'subtle',
    'class' => '-me-1 [[data-flux-input]:has(input:placeholder-shown)_&]:hidden [[data-flux-input]:has(input[disabled])_&]:hidden',
    'square' => true,
    'size' => null,
]);
@endphp

<flux:button
    :$attributes
    :size="$size === 'sm' || $size === 'xs' ? 'xs' : 'sm'"
    x-data="fluxInputClearable"
    x-on:click="clear()"
    tabindex="-1"
    aria-label="{{ __('Eingabe leeren') }}"
    data-flux-clear-button
>
    <flux:icon.x-mark :variant="$iconVariant" />
</flux:button>
