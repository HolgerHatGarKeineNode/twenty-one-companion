<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Standard-Brand
    |--------------------------------------------------------------------------
    |
    | Slug der Wortmarke, die für alle nicht zugeordneten Länder gilt.
    | International ist die Marke „TWENTY ONE".
    |
    */

    'default' => 'twenty-one',

    /*
    |--------------------------------------------------------------------------
    | Land → Brand
    |--------------------------------------------------------------------------
    |
    | Das Branding folgt dem gewählten LAND (über dessen Hauptsprache), nicht
    | der UI-Sprache. Ländercodes lowercase (ISO-3166-1 alpha-2), passend zu
    | App\Services\AppPreferences::country(). Quelle der Zuordnung: Portal
    | config/lang-country.php + app/helpers.php (get_domain_attributes).
    |
    */

    'countries' => [
        // Deutsch → EINUNDZWANZIG
        'de' => 'einundzwanzig',
        'at' => 'einundzwanzig',
        'ch' => 'einundzwanzig',
        'li' => 'einundzwanzig',

        // Lettland nutzt bewusst den deutschen Markennamen.
        'lv' => 'einundzwanzig',

        // Ungarisch → HUSZONEGY
        'hu' => 'huszonegy',

        // Niederländisch → EENENTWINTIG
        'nl' => 'eenentwintig',
        'be' => 'eenentwintig',

        // Polnisch → DWADZIEŚCIA JEDEN
        'pl' => 'dwadziescia-jeden',

        // Portugiesisch → VINTE E UM
        'pt' => 'vinte-e-um',
        'br' => 'vinte-e-um',

        // Spanisch (Spanien + Lateinamerika) → VEINTIUNO
        'es' => 'veintiuno',
        'ar' => 'veintiuno',
        'bo' => 'veintiuno',
        'cl' => 'veintiuno',
        'co' => 'veintiuno',
        'cr' => 'veintiuno',
        'cu' => 'veintiuno',
        'do' => 'veintiuno',
        'ec' => 'veintiuno',
        'gt' => 'veintiuno',
        'hn' => 'veintiuno',
        'mx' => 'veintiuno',
        'ni' => 'veintiuno',
        'pa' => 'veintiuno',
        'pe' => 'veintiuno',
        'pr' => 'veintiuno',
        'py' => 'veintiuno',
        'sv' => 'veintiuno',
        'uy' => 'veintiuno',
        've' => 'veintiuno',
    ],

];
