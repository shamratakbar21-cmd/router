<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_nodes', function (Blueprint $table): void {
            $table->text('desired_secret_configuration')->nullable()->after('desired_configuration');
            $table->text('applied_secret_configuration')->nullable()->after('applied_configuration');
        });

        Schema::table('configuration_revisions', function (Blueprint $table): void {
            $table->text('desired_secret_configuration')->nullable()->after('desired_configuration');
            $table->text('applied_secret_configuration')->nullable()->after('applied_configuration');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_revisions', function (Blueprint $table): void {
            $table->dropColumn([
                'desired_secret_configuration',
                'applied_secret_configuration',
            ]);
        });

        Schema::table('router_nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'desired_secret_configuration',
                'applied_secret_configuration',
            ]);
        });
    }
};
