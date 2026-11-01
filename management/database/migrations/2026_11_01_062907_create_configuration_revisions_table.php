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
        Schema::create('configuration_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('desired_configuration');
            $table->json('diff_summary')->nullable();
            $table->unsignedBigInteger('rollback_of_revision_id')->nullable();
            $table->string('apply_status')->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['router_node_id', 'revision_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuration_revisions');
    }
};
