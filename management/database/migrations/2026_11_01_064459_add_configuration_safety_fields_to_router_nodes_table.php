<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('router_nodes', function (Blueprint $table) {
            $table->unsignedBigInteger('desired_revision_id')->nullable()->after('current_revision_id');
            $table->unsignedBigInteger('applied_revision_id')->nullable()->after('desired_revision_id');
            $table->json('applied_configuration')->nullable()->after('desired_configuration');
            $table->unsignedInteger('configuration_lock_version')->default(0)->after('status');
            $table->uuid('pending_operation_uuid')->nullable()->after('configuration_lock_version');
            $table->timestamp('reconciliation_deadline_at')->nullable()->after('pending_operation_uuid');
            $table->timestamp('safe_mode_confirm_by')->nullable()->after('reconciliation_deadline_at');
            $table->string('safe_mode_status')->default('idle')->after('safe_mode_confirm_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router_nodes', function (Blueprint $table) {
            $table->dropColumn([
                'desired_revision_id',
                'applied_revision_id',
                'applied_configuration',
                'configuration_lock_version',
                'pending_operation_uuid',
                'reconciliation_deadline_at',
                'safe_mode_confirm_by',
                'safe_mode_status',
            ]);
        });
    }
};
