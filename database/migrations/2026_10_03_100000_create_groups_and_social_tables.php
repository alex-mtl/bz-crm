<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — groups (ФО §6.5, ТЗ §19) and the internal social network (ФО §6.4, ТЗ §18).
 * A post is stored once; who sees it is decided by its visibility rows, never by copies in feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->string('cover_code', 60)->nullable();
            $table->string('type', 10)->default('open');   // open | closed | secret
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'archived_at']);
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 12)->default('member');   // owner | admin | moderator | member
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'person_id']);
            $table->index(['person_id', 'role']);
        });

        Schema::create('group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 12)->default('pending');   // pending | approved | rejected
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['group_id', 'status']);
        });

        Schema::create('group_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // A personal invitation names the person; an invitation by link has a token instead.
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('pending');   // pending | accepted | declined | revoked
            $table->foreignId('invited_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'status']);
            $table->index(['group_id', 'status']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_person_id')->constrained('people')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->foreignId('repost_of_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('status', 12)->default('draft');        // draft | scheduled | published
            $table->string('visibility', 12)->default('private');  // public | regional | group | private | targeted
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_by_user_id')->nullable();
            $table->text('hidden_reason')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index(['status', 'publish_at']);
            $table->index(['author_person_id', 'status']);
            $table->index('visibility');
        });

        Schema::create('post_territories', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->primary(['post_id', 'territory_id']);
        });

        Schema::create('post_groups', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->primary(['post_id', 'group_id']);
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            // "Целевой список": chosen people and / or roles ("all regional heads").
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('role_code', 60)->nullable();
            $table->index(['person_id']);
            $table->index(['role_code']);
        });

        Schema::create('post_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('kind', 10);   // image | video | file
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('post_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('scope', 12);   // global | territory | group
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('pinned_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'scope', 'scope_id']);
            $table->index(['scope', 'scope_id']);
        });

        Schema::create('post_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('text', 255);
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::create('post_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('post_poll_options')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'person_id']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->cascadeOnDelete();
            $table->foreignId('quoted_comment_id')->nullable()->constrained('post_comments')->nullOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->foreignId('author_person_id')->constrained('people')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_by_user_id')->nullable();
            $table->text('hidden_reason')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'parent_id']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->string('reactable_type', 20);   // post | comment
            $table->unsignedBigInteger('reactable_id');
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('reaction_code', 60);
            $table->timestamps();
            $table->unique(['reactable_type', 'reactable_id', 'person_id']);
        });

        Schema::create('author_subscriptions', function (Blueprint $table) {
            $table->foreignId('follower_person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('author_person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['follower_person_id', 'author_person_id']);
        });

        Schema::create('moderation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reportable_type', 20);   // post | comment
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('reporter_person_id')->constrained('people')->cascadeOnDelete();
            $table->string('reason_code', 60);
            $table->text('comment')->nullable();
            $table->string('status', 12)->default('open');   // open | upheld | dismissed
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'reportable_type', 'reportable_id']);
        });

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action', 12);   // hide | restore | warn | mute | unmute
            $table->string('target_type', 20)->nullable();   // post | comment
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('moderator_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['person_id', 'action', 'expires_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        foreach (['moderation_actions', 'moderation_reports', 'author_subscriptions', 'reactions', 'post_comments', 'post_poll_votes',
            'post_poll_options', 'post_pins', 'post_revisions', 'post_attachments', 'post_targets', 'post_groups', 'post_territories', 'posts',
            'group_invitations', 'group_join_requests', 'group_members', 'groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
