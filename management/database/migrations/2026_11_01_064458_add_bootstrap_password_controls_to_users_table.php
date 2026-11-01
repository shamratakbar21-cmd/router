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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_rotate_password')->default(false)->after('is_admin');
            $table->timestamp('bootstrap_password_issued_at')->nullable()->after('must_rotate_password');
            $table->timestamp('password_rotated_at')->nullable()->after('bootstrap_password_issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'must_rotate_password',
                'bootstrap_password_issued_at',
                'password_rotated_at',
            ]);
        });
    }
};
