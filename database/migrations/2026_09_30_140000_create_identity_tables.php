<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Д-10: an account created through a provider whose e-mail already belongs to another account
        // is never merged automatically — it keeps no login e-mail of its own (unique allows many NULLs).
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Self-registration → application reviewed by users.approve (ФО §6.1).
        Schema::create('registration_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('granted_roles')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('person_type', 50)->default('employee');
            $table->string('token_hash', 64)->unique();
            $table->json('role_codes');
            $table->unsignedBigInteger('invited_by_user_id');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        // A user may have several ways to sign in (ФО §6.1).
        Schema::create('social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestamp('linked_at');
            $table->unique(['provider', 'provider_user_id']);
        });

        // Provider registry editable by the super admin without code changes (ADR-007).
        Schema::create('auth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('driver', 40);
            $table->string('display_name', 100);
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Д-10: possible second account — a hint only, resolved by a human.
        Schema::create('account_link_hints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('new_person_id');
            $table->unsignedBigInteger('existing_person_id');
            $table->json('reasons');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['new_person_id', 'existing_person_id']);
            $table->index('status');
        });

        // T3: known sign-in devices, to spot a sign-in from a new one.
        Schema::create('user_known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_known_devices');
        Schema::dropIfExists('account_link_hints');
        Schema::dropIfExists('auth_providers');
        Schema::dropIfExists('social_identities');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('registration_applications');
    }
};
