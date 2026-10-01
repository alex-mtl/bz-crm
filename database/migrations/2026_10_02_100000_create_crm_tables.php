<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — CRM (ФО §6.9, ТЗ §26–28, §68): the people registry grows a territory and a responsible unit for
 * people outside the org structure; relations, custom fields, interactions, de-duplication and merge, pipelines
 * and leads, appeals, segments, import and export batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Where a person outside the org structure lives and which unit works with them (scopes "Т" and "П").
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->foreignId('responsible_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->string('source_code', 60)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
        });

        Schema::table('person_contacts', function (Blueprint $table) {
            // ФО §6.9.1: the preferred channel to reach the person.
            $table->boolean('is_preferred')->default(false);
        });

        Schema::create('person_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 20);   // type | archived | restored | merged
            $table->string('old_value', 60)->nullable();
            $table->string('new_value', 60)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['person_id', 'created_at']);
        });

        Schema::create('person_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('related_person_id')->constrained('people')->cascadeOnDelete();
            $table->string('relation_code', 60);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'related_person_id', 'relation_code'], 'person_relations_unique');
            $table->index('related_person_id');
        });

        // Custom fields (ФО §6.9.1 "пользовательские поля"): the minimal part of the constructor; phase 9 extends it.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('code', 60);
            $table->string('name_ro', 150);
            $table->string('name_ru', 150);
            $table->string('name_en', 150);
            $table->string('field_type', 20);   // text | number | date | bool | select
            $table->json('options')->nullable();
            $table->json('applies_to')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity', 'code']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['custom_field_id', 'entity_id']);
            $table->index('entity_id');
        });

        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind_code', 60);
            $table->string('direction', 10)->nullable();   // in | out
            $table->timestamp('occurred_at');
            $table->text('summary')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->foreignId('author_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            // Stored once per pair: person_a_id < person_b_id.
            $table->foreignId('person_a_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('person_b_id')->constrained('people')->cascadeOnDelete();
            $table->json('reasons');
            $table->string('status', 20)->default('open');   // open | dismissed | merged
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['person_a_id', 'person_b_id']);
            $table->index('status');
        });

        Schema::create('person_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kept_person_id')->constrained('people');
            $table->foreignId('merged_person_id')->constrained('people');
            $table->unsignedBigInteger('merged_by_user_id')->nullable();
            // What was re-pointed (table.column => ids) and the merged card as it was: nothing is lost (ТЗ §27).
            $table->json('moved');
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name_ro', 150);
            $table->string('name_ru', 150);
            $table->string('name_en', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name_ro', 150);
            $table->string('name_ru', 150);
            $table->string('name_en', 150);
            $table->string('kind', 10)->default('open');   // open | won | lost
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['pipeline_id', 'code']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            // ТЗ §26: a lead references a Person, it is not another Person.
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('title', 255)->nullable();
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('status', 10)->default('open');   // open | won | lost | frozen
            $table->string('lost_reason_code', 60)->nullable();
            $table->text('status_note')->nullable();
            $table->date('frozen_until')->nullable();
            $table->string('source_code', 60)->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->index(['pipeline_id', 'stage_id', 'status']);
            $table->index(['person_id', 'pipeline_id']);
            $table->index(['status', 'frozen_until']);
        });

        Schema::create('lead_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id')->nullable();
            $table->string('from_status', 10)->nullable();
            $table->string('to_status', 10);
            $table->foreignId('moved_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('type_code', 60);
            $table->string('source_code', 60)->nullable();
            $table->string('status', 20)->default('new');   // new | in_progress | done | rejected
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index('person_id');
        });

        Schema::create('appeal_tasks', function (Blueprint $table) {
            $table->foreignId('appeal_id')->constrained('appeals')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['appeal_id', 'task_id']);
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->json('criteria');
            $table->string('visibility', 10)->default('personal');   // personal | shared
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['visibility', 'owner_user_id']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('format', 5);
            $table->string('status', 20)->default('uploaded');
            $table->json('options')->nullable();
            $table->json('totals')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('status', 20)->default('valid');   // valid | error | duplicate | imported | skipped
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('duplicate_of_person_id')->nullable();
            $table->unsignedBigInteger('person_id')->nullable();
            $table->timestamps();
            $table->index(['import_batch_id', 'status']);
        });

        Schema::create('export_batches', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('format', 5);
            $table->json('filters')->nullable();
            $table->string('status', 20)->default('queued');   // queued | ready | failed
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('finished_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['export_batches', 'import_rows', 'import_batches', 'segments', 'appeal_tasks', 'appeals', 'lead_stage_history', 'leads',
            'pipeline_stages', 'pipelines', 'person_merges', 'duplicate_candidates', 'interactions', 'custom_field_values', 'custom_fields',
            'person_relations', 'person_status_history'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('person_contacts', fn (Blueprint $table) => $table->dropColumn('is_preferred'));
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('territory_id');
            $table->dropConstrainedForeignId('responsible_unit_id');
            $table->dropColumn(['source_code', 'import_batch_id']);
        });
    }
};
