<?php

namespace Database\Factories;

use App\Models\RouterNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouterNode>
 */
class RouterNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'namespace_name' => 'nur-'.fake()->unique()->lexify('????'),
            'management_interface' => 'mgmt0',
            'management_cidr' => '192.0.2.1/24',
            'status' => 'draft',
            'configuration_lock_version' => 0,
            'desired_configuration' => [
                'namespace' => 'nur-test',
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
            ],
            'desired_revision_id' => null,
            'applied_revision_id' => null,
            'applied_configuration' => null,
            'pending_operation_uuid' => null,
            'reconciliation_deadline_at' => null,
            'safe_mode_confirm_by' => null,
            'safe_mode_status' => 'idle',
            'current_revision_id' => null,
        ];
    }
}
