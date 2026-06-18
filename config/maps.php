<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Leaflet-Tile-Layer
    |--------------------------------------------------------------------------
    |
    | Gemeinsame Tile-Konfiguration für die Karten-Seite und den Orts-Picker.
    | Dunkle Tiles (CARTO Dark Matter), passend zum dark-only Chrome der App.
    | Hier zentral, damit ein Provider-/Lizenz-Wechsel nur an EINER Stelle
    | passiert.
    |
    */

    'tiles' => [
        'url' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        'options' => [
            'minZoom' => 2,
            'maxZoom' => 18,
            'subdomains' => 'abcd',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
        ],
    ],

];
