<?php

namespace Database\Factories;

use App\Models\ConfigurationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigurationRevision>
 */
class ConfigurationRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'revision_number' => 1,
            'base_lock_version' => 0,
            'operation_uuid' => null,
            'confirm_by' => null,
            'confirmed_at' => null,
            'stale_at' => null,
            'desired_configuration' => [
                'namespace' => 'nur-test',
                'management' => [
                    'interface' => 'mgmt0',
                    'cidr' => '192.0.2.1/24',
                ],
            ],
            'applied_configuration' => null,
            'diff_summary' => null,
            'rollback_of_revision_id' => null,
            'apply_status' => 'pending',
            'applied_at' => null,
            'failure_reason' => null,
        ];
    }
}
