<?php

declare(strict_types=1);

/*
 * A real `outbox:work --once` whose push edge is SLOW and which records what it sent.
 *
 * THE SECOND HALF OF bin/outbox-kill-proof's question. That script proves one worker is
 * correct across a crash. This one proves TWO workers are correct against each other,
 * which is the property R-083 found false: the lease was taken for the whole batch at
 * claim time while the rows were processed one at a time, so any row the first worker had
 * not reached by `claim_time + lease_seconds` was claimable again and BOTH workers sent
 * it. The duplicate left no trace in the table -- the loser's keyed, status-guarded
 * finish() reports 0 and writes nothing -- so the only place it is visible is at the edge.
 * Hence this file: the edge is what counts the requests.
 *
 * WHY A SEPARATE ENTRY SCRIPT AND NOT A FLAG IN THE APPLICATION -- the same reason
 * outbox-proof-worker.php gives: a production code path an environment variable can make
 * hang is what bootstrap/app.php rejects in so many words. The application ships knowing
 * nothing about this file.
 *
 * WHAT IT WRITES. One line per request, `<endpoint>`, appended to the file named in argv.
 * Appended with LOCK_EX and one write per line, because two of these run at once and a
 * torn line would be indistinguishable from a lost send. Each process gets its OWN file;
 * the assert script unions them, which is where a duplicate becomes visible.
 *
 * THE SLEEP IS THE POINT. It is what makes one row's processing take measurable time, so
 * that a batch can outlive its lease while the worker is still walking it. Milliseconds,
 * from argv, so the driver owns the timing and this file states no policy.
 *
 * AND THE LEASE AND BATCH SIZE ARE OVERRIDDEN HERE, IN THE PROOF'S OWN PROCESS, rather
 * than through env() in config/webpush.php. Same reason outbox-proof-boot.php generates
 * its VAPID keypair here instead of shipping one: the application's configuration is what
 * production runs, and adding two tuning knobs to it so that a proof can reach them puts
 * the proof's needs into the product. config() after bootstrap is the narrowest possible
 * override and it dies with this process.
 */

use App\Features\Notifications\WebPushDispatcher;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @var Application $app */
$app = require __DIR__.'/outbox-proof-boot.php';

$log = (string) ($argv[1] ?? '');
$sleepMs = (int) ($argv[2] ?? 250);
$lease = (int) ($argv[3] ?? 1);
$batch = (int) ($argv[4] ?? 8);

if ($log === '') {
    fwrite(STDERR, "outbox-lease-worker: usage: outbox-lease-worker.php <log> [sleep-ms] [lease-s] [batch]\n");

    exit(1);
}

config([
    'webpush.worker.lease_seconds' => max(1, $lease),
    'webpush.worker.batch_size' => max(1, $batch),
]);

$app->instance(WebPushDispatcher::EDGE, new class($log, $sleepMs) implements ClientInterface
{
    public function __construct(private readonly string $log, private readonly int $sleepMs) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // RECORDED BEFORE THE SLEEP, not after. A request that reached the edge has been
        // sent whatever happens next, and recording it afterwards would let a worker
        // killed mid-send under-report -- which would make a duplicate look like a
        // single send, the one direction this proof must never fail in.
        file_put_contents($this->log, $request->getUri()."\n", FILE_APPEND | LOCK_EX);

        usleep($this->sleepMs * 1000);

        return new Response(201);
    }
});

$app->forgetInstance(WebPushDispatcher::class);

$kernel = $app->make(Kernel::class);
$status = $kernel->call('outbox:work', ['--once' => true]);

// Kernel::call() writes to a BufferedOutput, so the command's own tally line is invisible
// unless it is echoed. It is the direct reading of the mechanism -- `skipped N` is the
// number of rows this worker claimed and then did NOT send because the lease had moved --
// so the proof prints it beside the counts rather than inferring it from them.
echo trim($kernel->output()), PHP_EOL;

exit($status);
