<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Seeder;

/**
 * UserSeeder
 *
 * Inserts data into the corresponding table.
 */
class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        // Example with batch insert:
        $this->truncate('users');

        // Insert a single user using the factory
        $this->insertBatch('users', [
            UserFactory::new()->create([
                'name'=> 'Test User',
                'email'=> 'test@example.com',
                'password'=> hash_make('password'),
            ]),
        ]);
    }
}