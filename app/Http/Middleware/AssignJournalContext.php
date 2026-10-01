<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\JournalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignJournalContext
{
    public function __construct(private JournalContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = Str::isUuid($incoming) ? $incoming : (string) Str::uuid();

        $this->context->requestId = $requestId;
        $this->context->startCorrelation($requestId);
        $this->context->ipAddress = $request->ip();
        $this->context->userAgent = $request->userAgent();

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
