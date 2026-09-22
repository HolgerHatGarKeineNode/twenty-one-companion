<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- The island bundle (`resources/js/app.js`) evaluates on these pages too, and `core.ts`
     freezes `isMobile` from this flag the moment it does — every reader of it (the login
     sheet, secure storage, the image proxy, the association calls) keeps that value for the
     whole document. Without the line a host-first document booted the island as "web"
     (device sighting v1.13.0). Byte-identical to the line in `group::partials.head`, so
     Livewire's head merge on `wire:navigate` recognises it and adds no second copy.
     Safe since the device gate acts only on routes behind `nostr.auth`: host pages carry
     no such mark, so the flag does not send a guest away from them. --}}
<script>window.__nostrMobile = window.__nostrMobile ?? @js(\Einundzwanzig\Group\Chassis::istApp());</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    if (! localStorage.getItem('flux.appearance')) {
        localStorage.setItem('flux.appearance', 'dark');
    }
</script>
@fluxAppearance
