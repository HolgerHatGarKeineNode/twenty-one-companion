<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

{{-- The island's boot globals (`__nostrSpace`, `__nostrPortal`, `__nostrMobile`,
     `__nostrI18n`, …) from the package's ONE partial — the same lines the package layout's
     head includes, so the values cannot drift between the two layouts.

     The island bundle (`resources/js/app.js`) evaluates on these pages too and freezes
     its module constants from these globals the moment it does. Until the v1.13.0 device
     sighting this head set only `__nostrMobile`; everything else fell back to the
     island's code defaults — the space to `ws://localhost:3334/`, a refused socket and a
     refused NIP-11 fetch on every page of this layout (measured on build 142 via CDP on
     /ich/inhalte/orte and /ich/inhalte/meetups, none on /start).

     The script lines come out byte-identical to the package head's, so Livewire's head
     merge on `wire:navigate` recognises them and adds no second copy. Safe for guests:
     the device gate acts only on routes behind `nostr.auth`, and host pages carry no such
     mark. --}}
@include('group::partials.globals')

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    if (! localStorage.getItem('flux.appearance')) {
        localStorage.setItem('flux.appearance', 'dark');
    }
</script>
@fluxAppearance
