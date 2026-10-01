<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name_ro');
            $table->string('name_ru');
            $table->string('name_en');
            $table->json('unverified_locales')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Codes come from the permission catalog registered in code (Д-17); effect: allow | deny.
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission_code', 100);
            $table->string('effect', 5)->default('allow');
            $table->unique(['role_id', 'permission_code']);
            $table->index('permission_code');
        });

        // scope_type / scope_id are filled from phase 2 (territory / org unit scopes).
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('scope_type', 30)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_id'], 'user_roles_unique_assignment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
