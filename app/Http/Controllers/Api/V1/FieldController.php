<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AuthorizationService;
use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\OfflineSync;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\FieldSnapshot;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The API of the agitator's phone (ТЗ §33–34, ADR-013): the houses they answer for, the queue of offline
 * operations, voluntary location sharing. No rules live here — the same domain services as the screens.
 */
final class FieldController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly FieldSnapshot $snapshot,
        private readonly OfflineSync $sync,
        private readonly LocationSharing $sharing,
        private readonly FieldSettings $settings,
    ) {}

    /**
     * The device asks who it is talking as before sending its queue: the token for the next requests.
     */
    public function session(Request $request): JsonResponse
    {
        return response()->json(['user_id' => $this->user($request)->id, 'csrf' => csrf_token()]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorization->authorize($user, 'geo.visits.create');

        return response()->json(['data' => $this->snapshot->for($user)]);
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->authorization->authorize($user, 'geo.visits.create');
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'operations' => ['required', 'array', 'max:'.OfflineSync::MAX_BATCH],
        ]);

        return response()->json([
            'data' => $this->sync->apply($user, $validated['device_id'], array_values($validated['operations'])),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function location(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->state($user)]);
    }

    public function startSharing(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['minutes' => ['required', 'integer']]);

        return $this->attempt(function () use ($user, $validated): array {
            $this->sharing->start($user, (int) $validated['minutes']);

            return $this->state($user);
        });
    }

    public function stopSharing(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->sharing->stop($user);

        return response()->json(['data' => $this->state($user)]);
    }

    public function point(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        return $this->attempt(function () use ($user, $validated): array {
            $this->sharing->report(
                $user, (float) $validated['latitude'], (float) $validated['longitude'],
                isset($validated['accuracy']) ? (int) round((float) $validated['accuracy']) : null,
                isset($validated['recorded_at']) ? Carbon::parse($validated['recorded_at']) : null,
            );

            return $this->state($user);
        });
    }

    /**
     * @return array{sharing: bool, until: string|null, allowed_minutes: list<int>, may_share: bool}
     */
    private function state(User $user): array
    {
        $share = $this->sharing->current($user);

        return [
            'sharing' => $share instanceof LocationShare,
            'until' => $share?->expires_at->toIso8601String(),
            'allowed_minutes' => $this->settings->shareMinutes(),
            'may_share' => $this->authorization->can($user, 'geo.locations.share'),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    private function attempt(callable $action): JsonResponse
    {
        try {
            return response()->json(['data' => $action()]);
        } catch (DomainException $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 422);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
