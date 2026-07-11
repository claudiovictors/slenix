<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Seeder;

/**
 * DatabaseSeeder
 *
 * Main entry point. Call other seeders here.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class
        ]);
    }
}