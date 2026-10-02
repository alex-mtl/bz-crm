<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — the field part of the Geo module (ФО §6.11, ТЗ §31–34, ADR-013): the address directory, houses and
 * apartments, visits and notes, assignments of agitators, geozones with their bindings and crossings, voluntary
 * location sharing, vehicles with trackers, the ledger of offline operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The address directory: one street — one row, whatever way its name was typed.
        Schema::create('streets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->restrictOnDelete();
            $table->string('type_code', 30);
            $table->string('name', 150);
            $table->string('normalized', 150);
            $table->timestamps();
            $table->unique(['territory_id', 'type_code', 'normalized']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('street_id')->constrained('streets')->restrictOnDelete();
            $table->string('number', 30);
            $table->string('normalized_number', 30);
            $table->timestamps();
            $table->unique(['street_id', 'normalized_number']);
        });

        Schema::create('houses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->unique()->constrained('addresses')->restrictOnDelete();
            // Where the house lives on the territory tree — normally a polling district.
            $table->foreignId('territory_id')->constrained('territories')->restrictOnDelete();
            $table->string('type_code', 30);
            $table->unsignedSmallInteger('entrances')->nullable();
            $table->unsignedSmallInteger('floors')->nullable();
            $table->unsignedInteger('residents_count')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'archived_at']);
        });

        Schema::create('apartments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->string('number', 20);
            $table->unsignedSmallInteger('entrance')->nullable();
            $table->smallInteger('floor')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // The state after the last visit; the visits themselves are the history.
            $table->string('status_code', 30)->default('not_visited');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->foreignId('last_visit_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->date('next_visit_on')->nullable();
            $table->timestamps();
            $table->unique(['house_id', 'number']);
            $table->index(['house_id', 'status_code']);
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('status_code', 30);
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->timestamp('visited_at');
            $table->date('next_visit_on')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            // Set for a visit that came from the offline queue: the same operation never makes a second visit.
            $table->uuid('operation_id')->nullable()->unique();
            $table->string('source', 10)->default('online');
            $table->timestamps();
            $table->index(['house_id', 'visited_at']);
            $table->index(['person_id', 'visited_at']);
        });

        Schema::create('apartment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('author_person_id')->constrained('people')->restrictOnDelete();
            // personal — the author only; team — the staff with the right, in their scope.
            $table->string('visibility', 10);
            // Encrypted: what a resident said about their political views is sensitive.
            $table->text('body');
            $table->timestamps();
            $table->index(['apartment_id', 'visibility']);
        });

        // An agitator answers for a house — or for everything inside a territory (a polling district, a sector).
        Schema::create('field_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('house_id')->nullable()->constrained('houses')->cascadeOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->cascadeOnDelete();
            $table->foreignId('assigned_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->index(['person_id', 'ended_at']);
            $table->index(['house_id', 'ended_at']);
        });

        Schema::create('house_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('houses')->cascadeOnDelete();
            $table->foreignId('appeal_id')->constrained('appeals')->cascadeOnDelete();
            $table->foreignId('linked_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['house_id', 'appeal_id']);
        });

        // A geozone is an object: a name, an outline, people who answer for it, things bound to it.
        Schema::create('geo_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('color', 9)->default('#2563eb');
            $table->foreignId('territory_id')->nullable()->constrained('territories')->restrictOnDelete();
            // GeoJSON Polygon; read and written only through GeoService (ТЗ §32). The box is for a cheap pre-check.
            $table->longText('geometry');
            $table->decimal('min_latitude', 9, 6);
            $table->decimal('max_latitude', 9, 6);
            $table->decimal('min_longitude', 9, 6);
            $table->decimal('max_longitude', 9, 6);
            $table->boolean('notify_events')->default(true);
            $table->boolean('notify_crossings')->default(true);
            $table->foreignId('created_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('geo_zone_responsibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unique(['geo_zone_id', 'person_id']);
        });

        Schema::create('geo_zone_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // auto — the subject's point lies inside the outline; manual — bound by a person.
            $table->string('origin', 10)->default('manual');
            $table->foreignId('linked_by_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['geo_zone_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('plate', 20)->nullable();
            $table->string('type_code', 30);
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->text('description')->nullable();
            // Only the hash of the tracker's key is kept; the key is shown once.
            $table->string('tracker_key_hash', 64)->nullable()->unique();
            $table->timestamp('tracker_key_issued_at')->nullable();
            $table->decimal('last_latitude', 9, 6)->nullable();
            $table->decimal('last_longitude', 9, 6)->nullable();
            $table->timestamp('last_point_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // Voluntary location sharing: started by the person, for a limited time, stopped at any moment.
        Schema::create('location_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('stopped_at')->nullable();
            $table->decimal('last_latitude', 9, 6)->nullable();
            $table->decimal('last_longitude', 9, 6)->nullable();
            $table->unsignedInteger('last_accuracy')->nullable();
            $table->timestamp('last_point_at')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'expires_at']);
        });

        Schema::create('location_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_share_id')->nullable()->constrained('location_shares')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->cascadeOnDelete();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedInteger('accuracy')->nullable();
            $table->timestamp('recorded_at');
            $table->index(['location_share_id', 'recorded_at']);
            $table->index(['vehicle_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        // Who is inside which zone now, and the structured events of entering and leaving.
        Schema::create('geo_zone_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->string('mover_type', 20);
            $table->unsignedBigInteger('mover_id');
            $table->timestamp('entered_at');
            $table->unique(['geo_zone_id', 'mover_type', 'mover_id']);
        });

        Schema::create('geo_zone_crossings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->string('mover_type', 20);
            $table->unsignedBigInteger('mover_id');
            $table->string('direction', 5);
            $table->timestamp('occurred_at');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->index(['geo_zone_id', 'occurred_at']);
            $table->index(['mover_type', 'mover_id', 'occurred_at']);
        });

        // ТЗ §34: every offline operation is processed once, whatever number of times it is sent.
        Schema::create('field_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->string('device_id', 64);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity', 30);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('operation', 30);
            $table->json('payload');
            $table->timestamp('client_timestamp')->nullable();
            $table->string('status', 10);
            $table->json('result')->nullable();
            $table->unsignedInteger('received_count')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'field_operations', 'geo_zone_crossings', 'geo_zone_presences', 'location_points', 'location_shares', 'vehicles',
            'geo_zone_links', 'geo_zone_responsibles', 'geo_zones', 'house_appeals', 'field_assignments', 'apartment_notes',
            'visits', 'apartments', 'houses', 'addresses', 'streets',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
