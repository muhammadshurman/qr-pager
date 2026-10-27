<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The mocked push edge: a PSR-18 client that records what was sent and answers a script.
 *
 * THIS IS WHAT MAKES "push sent to a mocked edge only in the automated suite" TRUE RATHER
 * THAN INTENDED. minishlink/web-push v11 takes an injected ClientInterface -- which is why
 * v11 was chosen over v10.1.0, where the library reaches for Guzzle itself -- so the suite
 * replaces the edge at the container (WebPushDispatcher::EDGE) and there is no socket to
 * open, no DNS to resolve and no way for a test to reach a real push service even by
 * mistake. A hostname nobody resolves would not give that: it would give a connection
 * error, which is a different code path from a 201.
 *
 * NOT A MOCK OF OUR OWN CODE, which is the distinction §6 cares about ("no DB mocks for DB
 * behaviour"). Everything between the worker and this object is real: the real claim
 * against real Postgres, the real tenant scope, the real encrypter, the real VAPID signer,
 * the real request construction. What is substituted is somebody else's HTTP server, which
 * is the one thing a test cannot own -- and the requests it records are the real signed,
 * encrypted requests, so assertions about headers and payloads are assertions about what
 * would have gone out.
 */
final class RecordingPushEdge implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<int> */
    private array $script;

    private int $calls = 0;

    /**
     * `$onSend` RUNS INSIDE THE SEND, which is the only place some properties are
     * reachable. R-083 is about what a SECOND worker does while the first is part-way
     * through a batch it has already claimed, and "part-way through" is a window that
     * exists nowhere a test can otherwise stand: pass() claims and then walks the rows
     * with nothing in between for a test to hook. A callback here puts the test inside
     * that window -- it receives the request that is being sent and the 0-based call
     * index, so it can act between row 1 and row 2 exactly as a competing worker would.
     *
     * It is NOT a way to make production code branch: the edge is somebody else's HTTP
     * server and this object already stands in for it entirely.
     *
     * @param  list<int>|int  $statuses  one status per call, or one status for every call
     * @param  (callable(RequestInterface, int): void)|null  $onSend
     */
    public function __construct(
        array|int $statuses = 201,
        private readonly bool $throwInstead = false,
        private $onSend = null,
    ) {
        $this->script = is_int($statuses) ? [$statuses] : $statuses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->onSend !== null) {
            ($this->onSend)($request, $this->calls);
        }

        if ($this->throwInstead) {
            throw new class('the edge is unreachable') extends RuntimeException implements ClientExceptionInterface {};
        }

        // The last entry repeats, so a one-element script is "always this", and a
        // multi-element one is "this, then this, then the last forever". That covers both
        // "every device returns 410" and "fail, fail, then succeed" without two classes.
        $status = $this->script[min($this->calls, count($this->script) - 1)];
        $this->calls++;

        return new Response($status);
    }

    public function sentCount(): int
    {
        return count($this->requests);
    }

    /** @return list<string> */
    public function endpointsCalled(): array
    {
        return array_map(fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }

    public function headerOn(int $index, string $header): string
    {
        return $this->requests[$index]->getHeaderLine($header);
    }

    public function bodyOn(int $index): string
    {
        return (string) $this->requests[$index]->getBody();
    }
}
