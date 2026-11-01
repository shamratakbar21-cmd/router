<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('operator')->after('is_admin');
            $table->index('role');
        });

        Schema::create('license_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedInteger('duration_days')->nullable();
            $table->json('entitlements');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('license_activation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_plan_id')->constrained('license_plans')->cascadeOnDelete();
            $table->string('key_prefix', 16);
            $table->string('key_hash', 128)->unique();
            $table->string('customer_label')->nullable();
            $table->unsignedInteger('max_activations')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 32)->default('available');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['key_prefix', 'status']);
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('license_plan_id')->constrained('license_plans')->cascadeOnDelete();
            $table->foreignId('license_activation_key_id')->nullable()->constrained('license_activation_keys')->nullOnDelete();
            $table->string('customer_label')->nullable();
            $table->json('entitlements');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('offline_grace_days')->default(14);
            $table->string('status', 32)->default('active');
            $table->string('signing_key_id', 64);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->foreignId('router_node_id')->nullable()->constrained('router_nodes')->nullOnDelete();
            $table->string('device_id', 128);
            $table->string('device_fingerprint', 128)->nullable();
            $table->string('request_nonce', 128);
            $table->string('activation_mode', 32);
            $table->longText('signed_payload');
            $table->string('payload_hash', 128);
            $table->timestamp('activated_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();
            $table->unique(['license_id', 'device_id']);
            $table->unique('request_nonce');
            $table->index(['device_id', 'status']);
        });

        Schema::create('license_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->foreignId('license_activation_id')->nullable()->constrained('license_activations')->nullOnDelete();
            $table->foreignId('license_activation_key_id')->nullable()->constrained('license_activation_keys')->nullOnDelete();
            $table->string('scope', 32);
            $table->string('reason');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('license_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->foreignId('from_activation_id')->constrained('license_activations')->cascadeOnDelete();
            $table->string('from_device_id', 128);
            $table->string('to_device_id', 128)->nullable();
            $table->foreignId('to_activation_id')->nullable()->constrained('license_activations')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('router_nodes', function (Blueprint $table) {
            $table->string('device_id', 128)->nullable()->after('namespace_name');
            $table->foreignId('active_license_activation_id')
                ->nullable()
                ->after('appliance_ssh_known_host')
                ->constrained('license_activations')
                ->nullOnDelete();
            $table->timestamp('license_checked_at')->nullable();
            $table->string('license_status', 32)->nullable();
            $table->unique('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('router_nodes', function (Blueprint $table) {
            $table->dropUnique(['device_id']);
            $table->dropColumn([
                'device_id',
                'active_license_activation_id',
                'license_checked_at',
                'license_status',
            ]);
        });

        Schema::dropIfExists('license_transfers');
        Schema::dropIfExists('license_revocations');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('license_activation_keys');
        Schema::dropIfExists('license_plans');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
