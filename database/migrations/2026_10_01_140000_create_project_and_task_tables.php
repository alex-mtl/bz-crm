<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects and tasks (ФО §6.8, ТЗ §23–25) and the minimal task discussion chat (plan "Зависимости между фазами", п. 1).
 * Statuses, types and priorities are catalog codes (Д-16); "overdue" is computed, never stored (ФО §6.8.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->json('structure');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('manager_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('visibility', 15)->default('members');
            $table->string('status', 15)->default('draft');
            $table->string('health', 12)->default('on_track');
            $table->string('structure', 8)->default('flat');
            $table->boolean('strict_phases')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('budget_plan', 14, 2)->nullable();
            $table->decimal('budget_fact', 14, 2)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('project_templates')->nullOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'archived_at']);
        });

        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 200);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 15)->default('not_started');
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->decimal('budget_plan', 14, 2)->nullable();
            $table->decimal('budget_fact', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->timestamps();
            $table->unique(['project_id', 'person_id']);
        });

        // Phase dependencies: "start after the previous one is finished" (ФО §6.8.1) — the Gantt arrows.
        Schema::create('project_dependencies', function (Blueprint $table) {
            $table->foreignId('phase_id')->constrained('project_phases')->cascadeOnDelete();
            $table->foreignId('depends_on_phase_id')->constrained('project_phases')->cascadeOnDelete();
            $table->primary(['phase_id', 'depends_on_phase_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('type_code', 60);
            $table->string('status_code', 60)->default('draft');
            $table->string('priority_code', 60)->default('medium');
            $table->foreignId('creator_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            // Д-15: a person without an account (candidate, applicant) can only be the task's subject.
            $table->foreignId('subject_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->string('blocked_by', 255)->nullable();
            $table->date('on_hold_until')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('status_before', 60)->nullable();
            $table->json('recurrence')->nullable();
            $table->foreignId('recurrence_of_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['status_code', 'due_at']);
            $table->index('deleted_at');
        });

        Schema::create('task_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 10);
            $table->timestamps();
            $table->unique(['task_id', 'person_id', 'role']);
            $table->index(['person_id', 'role']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('title', 255);
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'depends_on_task_id']);
        });

        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedInteger('minutes');
            $table->date('spent_on');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        // ФО §6.8.4: the transition rules are data — the super admin can add statuses and transitions.
        Schema::create('task_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('from_status', 60);
            $table->string('to_status', 60);
            $table->string('requires', 10)->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['from_status', 'to_status']);
        });

        // ФО §6.8.5: people, money, transport, materials — plan vs fact, on a project, phase or task.
        Schema::create('project_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('project_phases')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->string('kind_code', 60);
            $table->string('description', 255);
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->decimal('plan_amount', 14, 2)->nullable();
            $table->decimal('fact_amount', 14, 2)->nullable();
            $table->timestamps();
        });

        // Minimal discussion chat (Messaging, phase 2): one thread per task; phase 6 builds on it.
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->unique(['chat_id', 'person_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('author_person_id')->constrained('people')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['messages', 'chat_members', 'chats', 'project_resources', 'task_status_transitions', 'task_time_entries',
            'task_dependencies', 'task_checklist_items', 'task_people', 'tasks', 'project_dependencies', 'project_members',
            'project_phases', 'projects', 'project_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
