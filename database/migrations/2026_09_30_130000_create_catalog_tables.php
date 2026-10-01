<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Д-16: extensible catalogs instead of hard-coded lists.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_code', 60);
            $table->string('code', 80);
            $table->string('name_ro');
            $table->string('name_ru');
            $table->string('name_en');
            $table->json('unverified_locales')->nullable();
            $table->json('properties')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamps();

            $table->unique(['catalog_code', 'code']);
            $table->index(['catalog_code', 'is_active', 'sort_order']);
        });

        Schema::create('catalog_item_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_code', 60);
            $table->json('names');
            $table->string('source_locale', 5);
            $table->text('justification');
            $table->json('properties')->nullable();
            $table->json('similar_item_ids')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('proposed_by_user_id');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->unsignedBigInteger('created_item_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'catalog_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_proposals');
        Schema::dropIfExists('catalog_items');
    }
};
