<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The page of the agitator at the entrance (ТЗ §33–34, ADR-013): a small application of its own that keeps
 * working without a network. The page is only a shell — the houses come through the API, the rules stay on the server.
 */
final class FieldAppController
{
    public function __invoke(Request $request, AuthorizationService $authorization): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive(), 403);
        $mayVisit = $authorization->can($user, 'geo.visits.create');
        $mayShare = $authorization->can($user, 'geo.locations.share');
        abort_unless($mayVisit || $mayShare, 403);

        $asset = fn (string $file): string => '/field-app/'.$file.'?v='.(@filemtime(public_path('field-app/'.$file)) ?: 1);

        return view('field.app', [
            'boot' => [
                'user' => ['id' => $user->id, 'name' => $user->person->fullName()],
                'csrf' => csrf_token(),
                'locale' => app()->getLocale(),
                'may_visit' => $mayVisit,
                'may_share' => $mayShare,
                'urls' => [
                    'session' => '/api/v1/field/session', 'snapshot' => '/api/v1/field/snapshot', 'sync' => '/api/v1/field/sync',
                    'location' => '/api/v1/field/location', 'panel' => '/admin', 'login' => '/admin/login',
                ],
                't' => trans('geo.app'),
            ],
            'script' => $asset('app.js'),
            'style' => $asset('app.css'),
        ]);
    }
}
