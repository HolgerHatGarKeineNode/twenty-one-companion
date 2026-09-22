<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base map (Leaflet + a MapLibre GL layer)
    |--------------------------------------------------------------------------
    |
    | Shared by the map view and the location picker, and the ONE place for a
    | provider change. OpenFreeMap "Dark": vector tiles without a key or a
    | request limit, commercial use allowed (https://openfreemap.org). CARTO,
    | used before, started answering 200 PNGs with a burned-in "API KEY
    | REQUIRED" watermark.
    |
    | The attribution comes from the style's own source (TileJSON of
    | tiles.openfreemap.org/planet): "OpenFreeMap © OpenMapTiles Data from
    | OpenStreetMap", the wording of OpenFreeMap's quick start. The Leaflet
    | attribution control shows it bottom-right once the style has loaded.
    |
    */

    'tiles' => [
        'style' => 'https://tiles.openfreemap.org/styles/dark',
        'minZoom' => 2,
        'maxZoom' => 18,
    ],

];
