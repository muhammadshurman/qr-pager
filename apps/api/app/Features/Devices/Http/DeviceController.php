<?php

declare(strict_types=1);

namespace App\Features\Devices\Http;

use App\Domain\Http\ProblemType;
use App\Features\Devices\DeviceTokens;
use App\Models\User;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * KDS device tokens: the credentials a kitchen screen authenticates with.
 *
 * A device token carries one tenant, one capability and an explicit expiry. The plaintext is
 * returned once, when the token is issued, and is never retrievable afterwards — a lost
 * token is revoked and re-issued rather than recovered.
 *
 * DOCBLOCKS HERE ARE PUBLISHED. Scramble ships a controller method's docblock as the
 * endpoint's `description` in `docs/openapi.json` (`R-052`, `R-056`), so every comment below
 * is written as public API text. Anything about internals, ceilings or infrastructure stays
 * out of these blocks and lives in `App\Features\Devices\DeviceTokens` instead.
 */
final class DeviceController
{
    public function __construct(private readonly DeviceTokens $tokens) {}

    /**
     * Issue a device token.
     *
     * Returns the token once. Store it on the device; it cannot be read back.
     */
    #[DocResponse(201, 'The token, its id and when it expires.')]
    #[DocResponse(422, 'The request was malformed.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'No credential, or none that resolves a tenant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function store(IssueDeviceTokenRequest $request): JsonResponse
    {
        $issuer = $request->user();

        if (! $issuer instanceof User) {
            // Unreachable behind RequireTenantScope, which answers 401 without a
            // principal. Asserted rather than assumed, because the alternative is a null
            // dereference in a credential-issuing path.
            throw new RuntimeException('a device token needs an issuing user');
        }

        try {
            $issued = $this->tokens->issue(
                issuer: $issuer,
                tenantId: $this->tenant(),
                name: (string) $request->string('name'),
                ability: $request->ability(),
                ttlMinutes: (int) $request->integer('ttl_minutes'),
                actorIp: $request->ip(),
            );
        } catch (InvalidArgumentException $refused) {
            // The service refuses what the request rules already refuse, so reaching here
            // means the two disagree -- which is worth a 422 rather than a 500, and worth
            // reporting so the disagreement is visible to an operator.
            report($refused);

            return response()->json([
                'message' => 'that device token could not be issued',
            ], 422);
        }

        return response()->json(['device' => $issued], 201);
    }

    /**
     * Revoke a device token.
     *
     * A token that does not belong to the current restaurant is reported as not found.
     */
    #[DocResponse(204, 'Revoked.')]
    #[DocResponse(404, 'No such device token here.', type: ProblemDocument::class)]
    #[DocResponse(401, 'No credential, or none that resolves a tenant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function destroy(Request $request, string $device): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new RuntimeException('revoking a device token needs an actor');
        }

        $revoked = $this->tokens->revoke($actor, $this->tenant(), $device, $request->ip());

        if (! $revoked) {
            // 404 AND NOT 403, which is §11.2's IDOR rule: a token belonging to another
            // tenant must be indistinguishable from one that does not exist. The three
            // ways revoke() can fail -- no such row, not a device token, pinned elsewhere
            // -- deliberately answer alike.
            return response()->json([
                'type' => ProblemType::NotFound->value,
                'title' => 'Not found.',
                'status' => 404,
                'instance' => $request->getPathInfo(),
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return response()->json(null, 204);
    }

    /** The tenant this request resolved, from the container and never from input. */
    private function tenant(): string
    {
        $tenantId = app(RequireTenantScope::BINDING);

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException('no tenant was published for this request');
        }

        return $tenantId;
    }
}
