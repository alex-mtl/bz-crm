<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo\Territory — the territory tree (Д-1, Д-7): country → macro-region → district/municipality → locality/sector.
 * `path` is a materialized path ("/1/7/42/") so "is inside this subtree" is a prefix comparison (Д-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('territories')->restrictOnDelete();
            $table->string('level', 30)->index();
            $table->string('code', 150)->unique();
            $table->string('name_ro', 150);
            $table->string('name_ru', 150);
            $table->string('name_en', 150);
            $table->json('search_aliases')->nullable();
            $table->string('iso', 10)->nullable();
            $table->unsignedBigInteger('geonameid')->nullable()->index();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('railway_station')->default(false);
            $table->text('description')->nullable();
            $table->string('path', 255)->default('')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Outlines are stored and processed only through GeoService (ТЗ §32).
        Schema::create('territory_boundaries', function (Blueprint $table) {
            $table->foreignId('territory_id')->primary()->constrained('territories')->cascadeOnDelete();
            $table->longText('geojson');
            $table->string('source', 255);
            $table->timestamps();
        });

        Schema::create('territory_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at');
            $table->unique(['territory_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_responsibles');
        Schema::dropIfExists('territory_boundaries');
        Schema::dropIfExists('territories');
    }
};
