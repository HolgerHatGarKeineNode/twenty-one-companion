<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Illuminate\Support\Facades\Route;

/**
 * A MapLibre style served by the app under test itself: no request leaves the machine.
 *
 * The production style is `https://tiles.openfreemap.org/styles/dark` (`config/maps.php`) and
 * loading it from a test means the suite depends on a third party being up and fast — and
 * leaves the machine. What the browser tests need from a base map is not OpenFreeMap's
 * cartography but the MECHANISM: the MapLibre layer draws something that is not a flat
 * fill, its attribution reaches the bottom-right Leaflet control, and nothing on the way
 * breaks. The style is answered from two routes this class registers on the test's own app
 * instance, so the same code path runs — only the provider is replaced. Not a `data:` URL:
 * the package pages send `connect-src 'self' ws: wss: http: https:` (`ContentSecurityPolicy`),
 * which blocks `fetch()` of a `data:` URL — measured, the style never loaded on `/bereich/meetups`.
 *
 * That the PRODUCTION config still names OpenFreeMap and not the former provider is a
 * server-side fact and is held by `tests/Feature/MapPageTest.php`.
 *
 * What is drawn: one small image (a patchwork of 64 dark shades, smoothed by the GPU)
 * stretched over Europe. An image source has no resolution to run out of, so no zoom level
 * of the map views (2..18) shows one flat colour — a vector grid would need a ladder of
 * hundreds of features to say the same. The attribution rides on a GeoJSON source, which
 * the style spec lets carry one.
 */
final class LocalMapStyle
{
    public const ATTRIBUTION = 'OpenFreeMap © OpenMapTiles Data from OpenStreetMap';

    private const SHADES = 64;

    public const STYLE_PATH = '/__test/map-style.json';

    public const IMAGE_PATH = '/__test/map-patchwork.png';

    /**
     * Answers the style and its image from the current app instance (a new one per test, so
     * nothing outlives the test) and returns the path to put into `maps.tiles.style`.
     */
    public static function serve(): string
    {
        Route::get(self::STYLE_PATH, fn () => response()->json(self::style(url('/'))));
        Route::get(self::IMAGE_PATH, fn () => response(self::patchworkPng(), 200, ['Content-Type' => 'image/png']));

        return self::STYLE_PATH;
    }

    /**
     * @return array<string, mixed>
     */
    public static function style(string $origin): array
    {
        return [
            'version' => 8,
            'name' => 'test-local',
            'sources' => [
                'patchwork' => [
                    'type' => 'image',
                    'url' => rtrim($origin, '/').self::IMAGE_PATH,
                    'coordinates' => [[-30.0, 70.0], [50.0, 70.0], [50.0, 30.0], [-30.0, 30.0]],
                ],
                'attribution' => [
                    'type' => 'geojson',
                    'attribution' => self::ATTRIBUTION,
                    'data' => [
                        'type' => 'Feature',
                        'properties' => new \stdClass,
                        'geometry' => ['type' => 'Point', 'coordinates' => [10.0, 50.0]],
                    ],
                ],
            ],
            'layers' => [
                ['id' => 'background', 'type' => 'background', 'paint' => ['background-color' => '#0e1116']],
                ['id' => 'patchwork', 'type' => 'raster', 'source' => 'patchwork', 'paint' => ['raster-resampling' => 'linear']],
                ['id' => 'attribution', 'type' => 'circle', 'source' => 'attribution', 'paint' => ['circle-radius' => 0]],
            ],
        ];
    }

    private static function patchworkPng(): string
    {
        $size = 48;
        $image = imagecreatetruecolor($size, $size);

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                $step = ($x * 7 + $y * 13) % self::SHADES;
                imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, 24 + $step, 30 + intdiv($step * 5, 2), 60 + intdiv($step * 3, 2)));
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
