<?php

namespace Database\Factories;

use App\Models\ObservedState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObservedState>
 */
class ObservedStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'agent',
            'summary' => [
                'namespace' => 'nur-test',
            ],
            'state' => [
                'links' => [],
                'addresses' => [],
                'routes' => [],
            ],
            'recorded_at' => now(),
        ];
    }
}
