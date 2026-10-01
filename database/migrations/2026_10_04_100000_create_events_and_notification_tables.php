<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: events (ФО §6.7, ТЗ §22) and the notification center (ФО §6.13, ТЗ §37).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            // Occurrences of a recurring event are ordinary rows sharing a series.
            $table->uuid('series_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type_code', 64);
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('visibility', 16)->index();
            $table->foreignId('organizer_person_id')->constrained('people');
            $table->json('reminder_minutes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->text('results')->nullable();
            $table->dateTime('results_published_at')->nullable();
            $table->foreignId('results_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('event_territories', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_id', 'territory_id']);
        });

        Schema::create('event_groups', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_id', 'group_id']);
        });

        Schema::create('event_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people');
            $table->foreignId('invited_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->dateTime('invited_at')->nullable();
            $table->string('rsvp', 16)->nullable();
            $table->string('rsvp_comment', 500)->nullable();
            $table->dateTime('rsvp_at')->nullable();
            $table->boolean('attended')->nullable();
            $table->foreignId('attendance_marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('attendance_marked_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'person_id']);
            $table->index(['person_id', 'rsvp']);
        });

        Schema::create('event_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minutes_before');
            $table->dateTime('remind_at')->index();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'minutes_before']);
        });

        Schema::create('event_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        // The stored notification knows its category and its subject: preferences, the center and retraction work on columns.
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('category', 64)->nullable()->index();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->dateTime('retracted_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['user_id', 'category', 'channel']);
        });

        Schema::create('notification_defaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['role_id', 'category', 'channel']);
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_user_id')->constrained('users');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_critical')->default(false);
            $table->json('audience')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });

        Schema::create('announcement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });

        Schema::create('notification_digests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('summary');
            $table->timestamps();
            // One digest per person and period: a repeated run of the scheduler finds it and sends nothing.
            $table->unique(['user_id', 'frequency', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digests');
        Schema::dropIfExists('announcement_receipts');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('notification_defaults');
        Schema::dropIfExists('notification_preferences');
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['category', 'subject_type', 'subject_id', 'retracted_at']);
        });
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('event_attachments');
        Schema::dropIfExists('event_reminders');
        Schema::dropIfExists('event_attendees');
        Schema::dropIfExists('event_groups');
        Schema::dropIfExists('event_territories');
        Schema::dropIfExists('events');
    }
};
