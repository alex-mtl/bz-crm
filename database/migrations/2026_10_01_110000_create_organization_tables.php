<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization: the unit tree (ФО §7, ТЗ §11), unit ↔ territories 0..N (Д-3),
 * membership — exactly one unit per person — and the stored direct manager (Д-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('head_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('path', 255)->default('')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('org_unit_territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->restrictOnDelete();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at');
            $table->unique(['org_unit_id', 'territory_id']);
        });

        // Current membership: the primary key on person_id enforces "exactly one unit" (Д-11).
        Schema::create('org_memberships', function (Blueprint $table) {
            $table->foreignId('person_id')->primary()->constrained('people')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->string('position', 150)->nullable();
            $table->foreignId('manager_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->boolean('manager_set_manually')->default(false);
            $table->timestamp('joined_at');
            $table->timestamps();
            $table->index('manager_person_id');
        });

        Schema::create('org_membership_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->restrictOnDelete();
            $table->string('position', 150)->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at');
            $table->string('left_reason', 30);
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->index(['person_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_membership_history');
        Schema::dropIfExists('org_memberships');
        Schema::dropIfExists('org_unit_territories');
        Schema::dropIfExists('org_units');
    }
};
