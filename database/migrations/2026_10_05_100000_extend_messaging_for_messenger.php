<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: the messenger (ФО §6.6, ТЗ §20–21). The minimal chat of phase 2 — one discussion per object — grows
 * into direct dialogs and group chats with threads, attachments, reactions, mentions and read state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            // direct | group | subject (the discussion of a task, a project, a group — as before).
            $table->string('type', 16)->default('subject')->index();
            $table->string('title')->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            // "lowerPersonId:higherPersonId": one dialog per pair, whoever starts it.
            $table->string('direct_key', 48)->nullable()->unique();
            $table->dateTime('last_message_at')->nullable()->index();
            $table->dateTime('archived_at')->nullable();
            $table->string('subject_type', 100)->nullable()->change();
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });

        Schema::table('chat_members', function (Blueprint $table): void {
            $table->string('role', 16)->default('member');
            // all | mentions | mute — what the member is notified about (ФО §6.6.4).
            $table->string('notify', 16)->default('all');
            // Read state (ТЗ §20 "MessageRead"): pointers instead of a row per message and reader.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->unsignedBigInteger('last_delivered_message_id')->nullable();
            $table->text('draft')->nullable();
            $table->index(['person_id', 'chat_id']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();
            $table->foreignId('parent_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->unsignedBigInteger('root_id')->nullable()->index();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->foreignId('quoted_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('forwarded_from_message_id')->nullable()->constrained('messages')->nullOnDelete();
            // text | poll | system
            $table->string('kind', 16)->default('text');
            // sent | scheduled
            $table->string('status', 16)->default('sent')->index();
            $table->dateTime('send_at')->nullable();
            $table->boolean('mentions_all')->default(false);
            $table->dateTime('pinned_at')->nullable();
            $table->foreignId('pinned_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // "Превратить обсуждение в поручение" (ФО §6.6.3): the task created from this thread.
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            // pending | clean | infected | skipped (no scanner configured)
            $table->string('scan_status', 16)->default('pending');
            $table->dateTime('scanned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('reaction_code', 64);
            $table->timestamps();
            $table->unique(['message_id', 'person_id']);
        });

        Schema::create('message_mentions', function (Blueprint $table): void {
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->primary(['message_id', 'person_id']);
        });

        Schema::create('message_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('text');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('message_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('message_poll_options')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'person_id']);
        });

        Schema::create('chat_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamps();
        });

        // Д-26: which role may start a direct dialog with which. A missing cell means "may".
        Schema::create('direct_message_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('recipient_role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('allowed');
            $table->timestamps();
            $table->unique(['sender_role_id', 'recipient_role_id']);
        });
    }

    public function down(): void
    {
        foreach (['direct_message_rules', 'chat_invitations', 'message_poll_votes', 'message_poll_options', 'message_mentions', 'message_reactions', 'message_attachments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('messages', function (Blueprint $table): void {
            foreach (['parent_id', 'quoted_message_id', 'forwarded_from_message_id', 'pinned_by_person_id', 'deleted_by_user_id', 'task_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['root_id', 'depth', 'kind', 'status', 'send_at', 'mentions_all', 'pinned_at', 'deleted_at']);
        });
        Schema::table('chat_members', function (Blueprint $table): void {
            $table->dropIndex(['person_id', 'chat_id']);
            $table->dropColumn(['role', 'notify', 'last_read_message_id', 'last_delivered_message_id', 'draft']);
        });
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_person_id');
            $table->dropUnique(['direct_key']);
            $table->dropColumn(['type', 'title', 'direct_key', 'last_message_at', 'archived_at']);
        });
    }
};
