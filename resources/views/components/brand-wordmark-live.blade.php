@php
    use App\Services\BrandResolver;
    use App\Support\Brand;

    $current = app(BrandResolver::class)->current();
@endphp

{{--
    Reaktive Wortmarke fürs Layout-Chrome (Top-Bar). Beim Laden wird die
    aktuelle Marke server-seitig sichtbar gerendert; clientseitig wechselt sie
    live auf das `brand-changed`-Event (Profil/Onboarding/Länder-Filter), denn
    das Layout selbst wird bei Livewire-Updates nicht neu gerendert. Alle
    Marken sind vorgerendert, nur die aktive ist sichtbar (kein Flackern, da
    der initiale display-State server-seitig gesetzt ist).
--}}
<div
    x-data="{ slug: '{{ $current->value }}' }"
    @brand-changed.window="slug = $event.detail.slug"
    {{ $attributes->merge(['class' => 'flex items-center']) }}
>
    @foreach (Brand::cases() as $b)
        <div
            x-show="slug === '{{ $b->value }}'"
            style="{{ $b === $current ? '' : 'display:none' }}"
            class="flex h-full items-center"
        >
            <x-dynamic-component
                :component="$b->wordmarkComponent()"
                class="h-full w-auto"
                :aria-label="$b->appName()"
            />
        </div>
    @endforeach
</div>
