<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Factory;
use Slenix\Database\Seeds\Fake;
use App\Models\User;

class UserFactory extends Factory
{
    /** @var string The model that this factory generates */
    protected string $model = User::class;

    /**
     * Define the model's default state.
     * All Fake::* values are randomly generated on each call.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'name'       => Fake::name(),
            'email'      => Fake::email(),
            'password'   => Fake::hashedPassword(),
            'created_at' => Fake::dateTime(),
            'updated_at' => Fake::dateTime(),
        ];
    }

    // public function admin(): static
    // {
    //     return $this->state(['role' => 'admin']);
    // }
    //
    // public function inactive(): static
    // {
    //     return $this->state(['is_active' => false]);
    // }
}