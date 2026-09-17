<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            DemoAccountSeeder::class,
            CatalogueSeeder::class,
            MessageTemplateSeeder::class,
            DeliverySeeder::class,
            LmsSeeder::class,
            ChatSeeder::class,
        ]);
    }
}
