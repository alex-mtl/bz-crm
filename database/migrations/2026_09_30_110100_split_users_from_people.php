<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('person_id')->nullable()->after('id');
            $table->string('status', 20)->default('pending_approval')->after('email');
            $table->string('locale', 5)->default('ro')->after('status');
            $table->unsignedInteger('session_epoch')->default(0);
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('password')->nullable()->change();
        });

        // Existing accounts (local admin from phase 0) get their own person card and stay active.
        foreach (DB::table('users')->whereNull('person_id')->get(['id', 'name', 'email']) as $user) {
            [$first, $last] = array_pad(explode(' ', (string) $user->name, 2), 2, null);
            $personId = DB::table('people')->insertGetId([
                'first_name' => $first !== '' ? $first : 'User',
                'last_name' => $last,
                'email' => $user->email,
                'person_type' => 'employee',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $user->id)->update(['person_id' => $personId, 'status' => 'active']);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unsignedBigInteger('person_id')->nullable(false)->change();
            $table->unique('person_id');
            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id']);
            $table->dropIndex(['status']);
            $table->string('name')->default('');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'person_id', 'status', 'locale', 'session_epoch', 'app_authentication_secret',
                'app_authentication_recovery_codes', 'last_login_at', 'deactivated_at',
            ]);
        });
    }
};
