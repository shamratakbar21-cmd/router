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
        Schema::create('router_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('namespace_name')->unique();
            $table->string('management_interface', 15);
            $table->string('management_cidr', 43);
            $table->string('status')->default('draft');
            $table->json('desired_configuration');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_nodes');
    }
};
