<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Д-18: a declarative retention date on the confidential layers. Kept indefinitely for now; empty = the default.
 */
return new class extends Migration
{
    private const array TABLES = ['profile_internal', 'hr_assessments', 'psychology_notes', 'security_notes', 'notes_360'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->date('retain_until')->nullable()->index();
            });
            DB::table($name)->whereNull('retain_until')->update(['retain_until' => config('retention.default_until')]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['retain_until']);
                $table->dropColumn('retain_until');
            });
        }
    }
};
