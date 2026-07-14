<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Kein lokaler Nutzer-Seed: die App authentifiziert ausschließlich über
     * den Portal-Token (SecureStorage) / Nostr — es gibt keine lokalen Konten.
     */
    public function run(): void
    {
        //
    }
}
