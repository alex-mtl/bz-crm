<?php

use App\Domain\Identity\Models\User;
use App\Domain\Profiles\Models\HrAssessment;
use App\Domain\Profiles\Models\Note360;
use Illuminate\Support\Facades\DB;

/*
 * Д-18: a declarative retention date on confidential layers — 2030-01-01 by default, an empty date means the same.
 */

it('writes the default retention date into a new confidential record', function () {
    $user = User::factory()->create();
    $assessment = HrAssessment::query()->create(['person_id' => $user->person_id, 'author_user_id' => $user->id, 'strengths' => 'x']);

    expect(DB::table('hr_assessments')->where('id', $assessment->id)->value('retain_until'))->toBe('2030-01-01')
        ->and($assessment->fresh()->retainUntil()->toDateString())->toBe('2030-01-01');
});

it('reads an empty retention date as the default', function () {
    $user = User::factory()->create();
    $note = Note360::query()->create(['subject_person_id' => $user->person_id, 'author_user_id' => $user->id,
        'author_person_id' => $user->person_id, 'type' => 'feedback', 'body' => 'x']);
    DB::table('notes_360')->where('id', $note->id)->update(['retain_until' => null]);

    expect($note->fresh()->retain_until)->toBeNull()
        ->and($note->fresh()->retainUntil()->toDateString())->toBe('2030-01-01');
});

it('follows the configured default', function () {
    config(['retention.default_until' => '2035-06-30']);
    $user = User::factory()->create();

    expect(HrAssessment::query()->create(['person_id' => $user->person_id, 'author_user_id' => $user->id])->retainUntil()->toDateString())
        ->toBe('2035-06-30');
});
