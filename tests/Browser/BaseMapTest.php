<?php

declare(strict_types=1);

use Tests\Browser\Support\PortalFixtures;
use Tests\Browser\Support\Sichtung;

/**
 * The base map (config/maps.php): OpenFreeMap "Dark" as a MapLibre GL layer inside Leaflet.
 *
 * CARTO, the provider before, answered 200 PNGs with a burned-in "API KEY REQUIRED"
 * watermark — a map that loads fine and says the wrong thing. So this measures what is ON
 * the canvas (many distinct dark colours from rendered vector data, not one flat fill),
 * where the requests go, and that the attribution the licence asks for stands bottom-right.
 */
afterEach(fn () => PortalFixtures::cleanUp());

it('renders the OpenFreeMap vector base map with its attribution, and nothing breaks', function (int $width) {
    PortalFixtures::filled();

    $page = visit('/bereich/meetups?ansicht=karte')->inDarkMode();
    $page->page()->setViewportSize($width, 900);
    Sichtung::instrument($page);

    for ($i = 0; $i < 100 && ! $page->script("() => /OpenFreeMap/.test(document.querySelector('.leaflet-bottom.leaflet-right .leaflet-control-attribution')?.textContent ?? '')"); $i++) {
        usleep(200_000);
    }
    usleep(2_000_000);

    $state = $page->script(<<<'JS'
        () => {
            const box = document.querySelector('.leaflet-container').getBoundingClientRect();
            const attribution = document.querySelector('.leaflet-bottom.leaflet-right .leaflet-control-attribution');
            return {
                width: document.documentElement.clientWidth,
                canvas: !!document.querySelector('.leaflet-gl-layer canvas'),
                box: { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) },
                attribution: attribution?.textContent.replace(/\s+/g, ' ').trim() ?? '',
                attributionVisible: !!attribution && attribution.offsetParent !== null,
            };
        }
    JS);
    $measured = Sichtung::measure($page);

    $file = 'basemap-'.$width;
    $page->page()->screenshot(true, $file);
    $png = imagecreatefrompng(base_path('tests/Browser/Screenshots/'.$file.'.png'));
    $colours = [];
    for ($y = $state['box']['y'] + 10; $y < $state['box']['y'] + $state['box']['h'] - 40; $y += 7) {
        for ($x = $state['box']['x'] + 10; $x < $state['box']['x'] + $state['box']['w'] - 10; $x += 7) {
            $colours[imagecolorat($png, $x, $y)] = true;
        }
    }

    $hosts = array_values(array_unique(array_column($measured['fremdAnfragen'], 'host')));

    expect($state['width'])->toBe($width)
        ->and($state['canvas'])->toBeTrue('no MapLibre canvas in the map')
        ->and(count($colours))->toBeGreaterThan(20, 'the map area is a flat fill — no vector data was drawn')
        ->and($state['attributionVisible'])->toBeTrue()
        ->and($state['attribution'])->toContain('OpenFreeMap')->toContain('© OpenMapTiles')->toContain('OpenStreetMap')
        ->and($hosts)->toContain('tiles.openfreemap.org')
        ->and(array_filter($hosts, fn (string $host): bool => str_contains($host, 'cartocdn')))->toBe([])
        ->and(array_filter($hosts, fn (string $host): bool => Sichtung::isProductionHost($host)))->toBe([])
        ->and($measured['consoleErrors'])->toBe([])
        ->and($measured['pageErrors'])->toBe([])
        ->and($measured['netzwerkFehler'])->toBe([]);
})->with([320, 1280]);

it('renders the same base map in the location picker of the city editor', function (int $width) {
    withPortalToken();
    withCachedPortalProfile(['id' => 7]);

    $page = visit('/ich/inhalte')->inDarkMode();
    $page->page()->setViewportSize($width, 900);
    Sichtung::instrument($page);

    $page->script("() => { window.Flux?.modal?.('create-city')?.show?.(); Livewire.dispatch('open-city-editor') }");

    for ($i = 0; $i < 100 && ! $page->script("() => /OpenFreeMap/.test([...document.querySelectorAll('.leaflet-control-attribution')].map((a) => a.textContent).join(' '))"); $i++) {
        usleep(200_000);
    }
    usleep(2_000_000);

    $state = $page->script(<<<'JS'
        () => {
            const picker = document.querySelector('.leaflet-container');
            picker?.scrollIntoView({ block: 'center' });
            const box = picker?.getBoundingClientRect();
            return {
                canvas: !!picker?.querySelector('.leaflet-gl-layer canvas'),
                box: box ? { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) } : null,
                attribution: picker?.querySelector('.leaflet-bottom.leaflet-right .leaflet-control-attribution')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
            };
        }
    JS);
    usleep(500_000);
    $state['box'] = $page->script("() => { const b = document.querySelector('.leaflet-container').getBoundingClientRect(); return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) } }");
    $measured = Sichtung::measure($page);

    $file = 'basemap-picker-'.$width;
    $page->page()->screenshot(true, $file);
    $png = imagecreatefrompng(base_path('tests/Browser/Screenshots/'.$file.'.png'));
    $colours = [];
    for ($y = max(0, $state['box']['y'] + 10); $y < min(imagesy($png), $state['box']['y'] + $state['box']['h'] - 40); $y += 5) {
        for ($x = $state['box']['x'] + 10; $x < $state['box']['x'] + $state['box']['w'] - 10; $x += 5) {
            $colours[imagecolorat($png, $x, $y)] = true;
        }
    }

    expect($state['canvas'])->toBeTrue('no MapLibre canvas in the picker')
        ->and(count($colours))->toBeGreaterThan(20, 'the picker is a flat fill — no vector data was drawn')
        ->and($state['attribution'])->toContain('OpenFreeMap')->toContain('© OpenMapTiles')->toContain('OpenStreetMap')
        ->and($measured['pageErrors'])->toBe([])
        ->and($measured['consoleErrors'])->toBe([]);
})->with([320, 1280]);
