<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Person = the real-world human (ТЗ §12). May exist without a user account.
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->string('person_type', 50)->default('applicant');
            $table->string('preferred_locale', 5)->default('ro');
            // Д-10: a card confirmed as a duplicate points to the card it duplicates; history is kept.
            $table->unsignedBigInteger('duplicate_of_person_id')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
