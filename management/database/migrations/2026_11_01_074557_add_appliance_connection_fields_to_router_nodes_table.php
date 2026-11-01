<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_nodes', function (Blueprint $table): void {
            $table->string('appliance_host')->nullable()->after('management_cidr');
            $table->unsignedSmallInteger('appliance_ssh_port')->nullable()->after('appliance_host');
            $table->string('appliance_ssh_user')->nullable()->after('appliance_ssh_port');
            $table->text('appliance_ssh_private_key')->nullable()->after('appliance_ssh_user');
            $table->text('appliance_ssh_known_host')->nullable()->after('appliance_ssh_private_key');
        });
    }

    public function down(): void
    {
        Schema::table('router_nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'appliance_host',
                'appliance_ssh_port',
                'appliance_ssh_user',
                'appliance_ssh_private_key',
                'appliance_ssh_known_host',
            ]);
        });
    }
};
