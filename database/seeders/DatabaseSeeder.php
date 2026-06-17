<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            /*
            |--------------------------------------------------------------------------
            | Core Authentication Data
            |--------------------------------------------------------------------------
            | Roles must be created before users because users depend on roles.
            */
            RoleSeeder::class,
            UserSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Geological Document Management Data
            |--------------------------------------------------------------------------
            | Creates the geological category hierarchy and the reusable
            | metadata schemas for boreholes, groundwater, rock samples,
            | mineral occurrences, faults, and other geological records.
            */
            GeologicalDocumentCategorySeeder::class,
            GeologicalMetadataSchemaSeeder::class,
        ]);
    }
}