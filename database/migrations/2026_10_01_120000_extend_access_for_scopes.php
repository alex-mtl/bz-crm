<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the access model (ADR-003, ADR-008): scopes of assignments, data layers of permissions,
 * role inheritance, delegation and territory grants (Д-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name_en');
            $table->foreignId('inherits_role_id')->nullable()->after('is_system')->constrained('roles')->nullOnDelete();
        });

        // null = the assignment's scope decides; own / related = only own or related objects (Д-17).
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->string('data_scope', 10)->nullable()->after('effect');
        });

        // scope_type: null (organization) | org_unit | territory | own_unit | own_territories
        Schema::table('user_roles', function (Blueprint $table) {
            $table->string('kind', 15)->default('role')->after('scope_id');
            $table->text('reason')->nullable()->after('expires_at');
            $table->timestamp('expiry_recorded_at')->nullable()->after('reason');
            $table->index('expires_at');
        });

        // Direct territory access of a person (Д-3): who, when, why, until when; revocation kept as history.
        Schema::create('territory_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->restrictOnDelete();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->text('reason');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->unsignedBigInteger('ended_by_user_id')->nullable();
            $table->text('end_comment')->nullable();
            $table->index(['person_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_grants');
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['kind', 'reason', 'expiry_recorded_at']);
        });
        Schema::table('role_permissions', fn (Blueprint $table) => $table->dropColumn('data_scope'));
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inherits_role_id');
            $table->dropColumn('description');
        });
    }
};
