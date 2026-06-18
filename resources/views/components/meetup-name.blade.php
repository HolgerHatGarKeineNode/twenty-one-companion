@props(['name'])

@php
    // Marken-Präfix optisch zurücknehmen, damit der unterscheidende Teil
    // (Stadt/Ort) führt. Der volle Name bleibt erhalten (Detail-Header zeigt
    // ihn unverändert). Trennlogik testbar in App\Support\Brand.
    $parts = \App\Support\Brand::splitDisplayName($name);
@endphp

@if ($parts['prefix'] !== null)
    <span {{ $attributes->class('truncate') }}><span class="font-normal text-zinc-400 dark:text-zinc-500">{{ $parts['prefix'] }}</span> <span class="font-semibold">{{ $parts['rest'] }}</span></span>
@else
    <span {{ $attributes->class('truncate font-semibold') }}>{{ $name }}</span>
@endif
