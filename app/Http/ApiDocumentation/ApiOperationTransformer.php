<?php

namespace App\Http\ApiDocumentation;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Exceptions\OpenApiReferenceTargetNotFoundException;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Corrects what Scramble infers about each API operation.
 *
 * - A record of another user answers 404, because the policies deny as not found.
 *   Scramble documents a 403 for every route that authorizes, so the 403 is dropped
 *   where the route authorizes against one of its parameters (a policy); it stays
 *   for gates such as `operate`, which do answer 403.
 * - Every request may carry an `X-Correlation-ID`.
 * - A rate-limited route may answer 429, with the wait in `Retry-After`.
 */
final class ApiOperationTransformer implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($this->authorizesAgainstRouteParameter($routeInfo)) {
            $operation->responses = array_values(array_filter(
                $operation->responses ?? [],
                fn (Response|Reference $response): bool => $this->statusOf($response) !== HttpResponse::HTTP_FORBIDDEN,
            ));
        }

        if ($this->isRateLimited($routeInfo)) {
            $operation->addResponse($this->tooManyRequestsResponse());
        }

        $operation->addParameters([
            Parameter::make('X-Correlation-ID', 'header')
                ->description('A UUID that ties the request to its log entries, jobs and provider calls. It is echoed in the response; a new one is created when it is missing or not a UUID.')
                ->setSchema(Schema::fromType((new StringType)->format('uuid')))
                ->example('0b9e8c1a-4d2f-4e7b-8a3c-6f1d2e9b7a54'),
        ]);
    }

    /**
     * Determine whether the action authorizes with `#[Authorize('ability', 'routeParameter')]`.
     */
    private function authorizesAgainstRouteParameter(RouteInfo $routeInfo): bool
    {
        foreach ($routeInfo->reflectionMethod()?->getAttributes(Authorize::class) ?? [] as $attribute) {
            if (count($attribute->getArguments()) > 1) {
                return true;
            }
        }

        return false;
    }

    private function isRateLimited(RouteInfo $routeInfo): bool
    {
        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                return true;
            }
        }

        return false;
    }

    private function tooManyRequestsResponse(): Response
    {
        $body = (new ObjectType)->addProperty('message', new StringType)->setRequired(['message']);

        return Response::make(HttpResponse::HTTP_TOO_MANY_REQUESTS)
            ->setDescription('Too many requests from this account')
            ->setContent('application/json', Schema::fromType($body))
            ->addHeader('Retry-After', (new Header)
                ->setDescription('Seconds to wait before the account may make requests again.')
                ->setSchema(Schema::fromType(new IntegerType))
                ->setExample(30));
    }

    private function statusOf(Response|Reference $response): ?int
    {
        if ($response instanceof Response) {
            return (int) $response->code;
        }

        try {
            $resolved = $response->resolve();
        } catch (OpenApiReferenceTargetNotFoundException) {
            return null;
        }

        return $resolved instanceof Response ? (int) $resolved->code : null;
    }
}
