<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Д-22 — what the prototype did and the specification lacked: access for an existing card, automatic enrolment
 * into a pipeline with the responsible found by territory, deadlines of pipeline stages, priority and
 * first-response deadline of appeals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // An invitation for a card that already exists: accepting it gives that card an account.
            $table->unsignedBigInteger('person_id')->nullable()->index();
        });

        Schema::table('pipelines', function (Blueprint $table) {
            // Person types whose new cards enter the pipeline by themselves.
            $table->json('auto_enroll_types')->nullable();
            $table->boolean('auto_assign_by_territory')->default(false);
        });

        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->unsignedInteger('sla_hours')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            // Set on entering a stage that has a deadline; "overdue in the stage" is computed from it, never stored.
            $table->timestamp('stage_due_at')->nullable();
            $table->index(['status', 'stage_due_at']);
        });

        Schema::table('appeals', function (Blueprint $table) {
            $table->string('priority_code', 60)->default('normal');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appeals', fn (Blueprint $table) => $table->dropColumn(['priority_code', 'first_response_due_at', 'first_responded_at']));
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['status', 'stage_due_at']);
            $table->dropColumn('stage_due_at');
        });
        Schema::table('pipeline_stages', fn (Blueprint $table) => $table->dropColumn('sla_hours'));
        Schema::table('pipelines', fn (Blueprint $table) => $table->dropColumn(['auto_enroll_types', 'auto_assign_by_territory']));
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
