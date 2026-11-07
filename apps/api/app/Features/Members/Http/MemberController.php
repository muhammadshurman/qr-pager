<?php

declare(strict_types=1);

namespace App\Features\Members\Http;

use App\Domain\Http\ProblemType;
use App\Features\Members\MemberDirectory;
use App\Models\User;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * This restaurant's staff: who belongs to it, and with what role.
 *
 * Every route here acts inside the restaurant the request resolved. A member id belonging to
 * another restaurant is reported as not found.
 *
 * DOCBLOCKS HERE ARE PUBLISHED as the endpoint descriptions in `docs/openapi.json`
 * (`R-052`, `R-056`), so they are written as public API text and nothing about internals
 * appears in them.
 */
final class MemberController
{
    public function __construct(private readonly MemberDirectory $members) {}

    /**
     * List this restaurant's staff.
     */
    #[DocResponse(200, 'The staff of this restaurant, oldest membership first.')]
    #[DocResponse(401, 'No credential, or none that resolves a restaurant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function index(): JsonResponse
    {
        return response()->json(['members' => $this->members->all($this->tenant())]);
    }

    /**
     * Add an existing user to this restaurant's staff.
     *
     * The user must already have an account. Creating one by invitation is not part of this
     * endpoint.
     */
    #[DocResponse(201, 'The membership that now exists.')]
    #[DocResponse(404, 'No user with that address.', type: ProblemDocument::class)]
    #[DocResponse(422, 'The request was malformed.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'No credential, or none that resolves a restaurant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function store(StoreMemberRequest $request): Response
    {
        $added = $this->members->add(
            tenantId: $this->tenant(),
            email: (string) $request->string('email'),
            role: $request->role(),
            actorUserId: $this->actor($request),
            actorIp: $request->ip(),
        );

        if ($added === null) {
            // 404 and not 422: "no user with that address" is the same answer whether the
            // address is unknown or belongs to somebody this caller may not see, and a
            // distinguishable one would make this endpoint an account-existence oracle for
            // the whole platform -- users is global.
            return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
        }

        return response()->json(['member' => $added], 201);
    }

    /**
     * Change a member's role.
     *
     * The last owner of a restaurant cannot be demoted.
     */
    #[DocResponse(200, 'The role now in force.')]
    #[DocResponse(404, 'No such member here, or the change is not allowed.', type: ProblemDocument::class)]
    #[DocResponse(422, 'The request was malformed.', type: ValidationProblemDocument::class)]
    #[DocResponse(401, 'No credential, or none that resolves a restaurant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function update(UpdateMemberRoleRequest $request, string $member): Response
    {
        $changed = $this->members->changeRole(
            tenantId: $this->tenant(),
            membershipId: $member,
            role: $request->role(),
            actorUserId: $this->actor($request),
            actorIp: $request->ip(),
        );

        if (! $changed) {
            return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
        }

        return response()->json(['member' => ['id' => $member, 'role' => $request->role()->value]]);
    }

    /**
     * Remove a member from this restaurant.
     *
     * The last owner of a restaurant cannot be removed.
     */
    #[DocResponse(204, 'Removed.')]
    #[DocResponse(404, 'No such member here, or the removal is not allowed.', type: ProblemDocument::class)]
    #[DocResponse(401, 'No credential, or none that resolves a restaurant.', type: ProblemDocument::class)]
    #[DocResponse(403, 'That is not permitted.', type: ProblemDocument::class)]
    public function destroy(Request $request, string $member): Response
    {
        $removed = $this->members->remove(
            tenantId: $this->tenant(),
            membershipId: $member,
            actorUserId: $this->actor($request),
            actorIp: $request->ip(),
        );

        if (! $removed) {
            return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
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

    private function actor(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('a membership change needs an actor');
        }

        return (string) $user->getKey();
    }
}
