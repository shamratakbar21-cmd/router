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
        Schema::create('observed_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('configuration_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('agent');
            $table->json('summary')->nullable();
            $table->json('state');
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observed_states');
    }
};
