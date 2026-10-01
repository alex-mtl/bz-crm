<?php

use App\Domain\Audit\EventTypeRegistry;

it('has a ro, ru and en label for every registered event type', function (string $locale) {
    $missing = [];
    foreach (app(EventTypeRegistry::class)->all() as $type) {
        if (str_starts_with($type->code, 'test.')) {
            continue;
        }
        if (trans($type->labelKey(), [], $locale) === $type->labelKey()) {
            $missing[] = $type->code;
        }
    }

    expect($missing)->toBe([]);
})->with(['ro', 'ru', 'en']);
