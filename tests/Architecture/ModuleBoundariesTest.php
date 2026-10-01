<?php

// The auth model must implement Filament's panel/MFA contracts; that is the only
// sanctioned presentation dependency inside the domain (ADR-007).
arch('domain modules do not depend on the presentation layer')
    ->expect('App\Domain')
    ->not->toUse(['App\Filament', 'App\Http', 'Filament', 'Livewire'])
    ->ignoring('App\Domain\Identity\Models\User');

arch('infrastructure adapters do not depend on the presentation layer')
    ->expect('App\Infrastructure')
    ->not->toUse(['App\Filament', 'App\Http', 'Filament', 'Livewire']);

arch('domain modules reach adapters only through their ports')
    ->expect('App\Domain')
    ->not->toUse('App\Infrastructure');

arch('support code does not depend on domain modules')
    ->expect('App\Support')
    ->not->toUse('App\Domain');

arch('no debugging helpers are committed')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('application code uses strict types')
    ->expect(['App\Domain', 'App\Infrastructure', 'App\Support'])
    ->toUseStrictTypes();
