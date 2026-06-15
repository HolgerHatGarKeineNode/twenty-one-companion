@props([
    // Optionale Override-Marke (Brand-Enum oder Slug). Ohne Angabe wird die
    // aktuelle Marke aus der gewählten Region aufgelöst.
    'brand' => null,
])

@php
    use App\Services\BrandResolver;
    use App\Support\Brand;

    $resolved = match (true) {
        $brand instanceof Brand => $brand,
        is_string($brand) && $brand !== '' => Brand::tryFrom($brand) ?? app(BrandResolver::class)->current(),
        default => app(BrandResolver::class)->current(),
    };
@endphp

<x-dynamic-component
    :component="$resolved->wordmarkComponent()"
    {{ $attributes->merge(['aria-label' => $resolved->appName()]) }}
/>
