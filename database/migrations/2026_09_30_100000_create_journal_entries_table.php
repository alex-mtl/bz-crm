<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only business event journal (ADR-006). No foreign keys on purpose:
        // journal rows must survive deletion/archiving of the objects they describe.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            $table->string('event_type', 100);
            $table->string('category', 30);
            $table->string('severity', 10);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_person_id')->nullable();
            $table->string('acting_as', 20)->default('own');
            $table->string('acting_as_ref', 64)->nullable();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->string('correlation_id', 36);

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
