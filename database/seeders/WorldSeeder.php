<?php

namespace Database\Seeders;

use App\Services\WorldDataImporter;
use Illuminate\Database\Seeder;

class WorldSeeder extends Seeder
{
    public function run(): void
    {
        app(WorldDataImporter::class)->import();
    }
}
