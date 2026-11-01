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
        Schema::table('configuration_revisions', function (Blueprint $table) {
            $table->unsignedInteger('base_lock_version')->default(0)->after('revision_number');
            $table->uuid('operation_uuid')->nullable()->after('base_lock_version');
            $table->timestamp('confirm_by')->nullable()->after('operation_uuid');
            $table->timestamp('confirmed_at')->nullable()->after('confirm_by');
            $table->timestamp('stale_at')->nullable()->after('confirmed_at');
            $table->json('applied_configuration')->nullable()->after('desired_configuration');
            $table->text('failure_reason')->nullable()->after('apply_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configuration_revisions', function (Blueprint $table) {
            $table->dropColumn([
                'base_lock_version',
                'operation_uuid',
                'confirm_by',
                'confirmed_at',
                'stale_at',
                'applied_configuration',
                'failure_reason',
            ]);
        });
    }
};
