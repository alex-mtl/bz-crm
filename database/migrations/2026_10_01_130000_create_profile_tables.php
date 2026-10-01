<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profiles (ФО §6.3): the open layer filled by the owner, with per-field visibility (Д-13), and the confidential
 * layers as physically separate entities (ФО §6.3.4) — never flags on one row.
 * visibility: all | region | colleagues | management (nested, Д-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_profiles', function (Blueprint $table) {
            $table->foreignId('person_id')->primary()->constrained('people')->cascadeOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('cover_code', 60)->nullable();
            $table->text('bio')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('personal_visibility', 12)->default('management');
            $table->json('skills')->nullable();
            $table->json('interests')->nullable();
            $table->json('languages')->nullable();
            $table->string('skills_visibility', 12)->default('all');
            $table->timestamps();
        });

        Schema::create('person_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('contact_type', 60);
            $table->string('value', 255);
            $table->string('visibility', 12)->default('colleagues');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['person_id', 'sort_order']);
        });

        // Internal layer (ФО §6.3.2): address, personal phone, emergency contacts — encrypted at rest, hence text.
        Schema::create('profile_internal', function (Blueprint $table) {
            $table->foreignId('person_id')->primary()->constrained('people')->cascadeOnDelete();
            $table->text('home_address')->nullable();
            $table->text('personal_phone')->nullable();
            $table->text('emergency_contacts')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('potential', 20)->nullable();
            $table->text('strengths')->nullable();
            $table->text('development')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamps();
            $table->index('person_id');
        });

        Schema::create('psychology_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id')->index();
            $table->text('body');
            $table->timestamps();
            $table->index('person_id');
        });

        Schema::create('security_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->timestamps();
            $table->index('person_id');
        });

        // "360" notes (Д-14): the type is fixed at creation; the subject never sees them.
        Schema::create('notes_360', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('author_user_id')->index();
            $table->foreignId('author_person_id')->constrained('people')->cascadeOnDelete();
            $table->string('type', 10);
            $table->text('body');
            $table->timestamps();
            $table->index('subject_person_id');
        });
    }

    public function down(): void
    {
        foreach (['notes_360', 'security_notes', 'psychology_notes', 'hr_assessments', 'profile_internal', 'person_contacts', 'person_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
