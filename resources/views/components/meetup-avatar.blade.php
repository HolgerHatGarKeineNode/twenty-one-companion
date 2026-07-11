@props([
    'logo' => null,
    'name',
    'size' => 'lg',
])

{{-- Meetup-Avatar mit dezentem Ring für Tiefe (Phase 1.6). --}}
@php
    $ring = 'shrink-0 ring-1 ring-black/5 dark:ring-white/10';
    // Bild-URLs vom Portal normalisieren:
    //  · /img/einundzwanzig* = Default-Platzhalter für fehlende Bilder (404) → als
    //    „kein Bild" behandeln, damit der Initialen-Fallback greift statt eines
    //    kaputten Bildes.
    //  · /profile-photos/ = ohne Punkt vor der Extension gespeichert → das Portal
    //    liefert content-type application/octet-stream + nosniff, der Browser rendert
    //    sie NICHT. Über den ImageProxy geleitet (wie die Chat-Avatare) kommt ein
    //    sauberer image/*-Typ zurück.
    //  · storage-Thumbnails laden direkt korrekt → unverändert (kein Proxy-Umweg).
    $resolvedLogo = $logo;
    if ($logo && str_contains($logo, '/img/einundzwanzig')) {
        $resolvedLogo = null;
    } elseif ($logo && str_contains($logo, '/profile-photos/')) {
        $resolvedLogo = \Einundzwanzig\Group\ImageProxy::url($logo);
    }
@endphp
@php
    // Größe/Radius wie flux:avatar, aber als eigenes <img>, damit loading="lazy"
    // greift: in langen Listen (Meetups) lädt der Browser nur die sichtbaren
    // Logos statt sofort aller ~200 — der Hauptgrund für den langsamen Aufbau.
    $sizeClasses = match ($size) {
        'xl' => 'size-16 rounded-xl',
        'md' => 'size-10 rounded-lg',
        'sm' => 'size-8 rounded-md',
        'xs' => 'size-6 rounded-sm',
        default => 'size-12 rounded-lg', // lg
    };
@endphp
@if ($resolvedLogo)
    <img
        src="{{ $resolvedLogo }}"
        alt="{{ $name }}"
        loading="lazy"
        decoding="async"
        class="{{ $sizeClasses }} {{ $ring }} bg-zinc-100 object-cover dark:bg-zinc-800"
    />
@else
    <flux:avatar :name="$name" :size="$size" :class="$ring"/>
@endif
