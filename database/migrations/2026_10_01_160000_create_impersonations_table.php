<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Д-19: impersonation sessions. Each one is a record with the reason and its limits; journal entries made
 * during it point here through acting_as_ref.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_user_id')->constrained('users');
            $table->foreignId('target_user_id')->constrained('users');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['target_user_id', 'started_at']);
            $table->index(['impersonator_user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
